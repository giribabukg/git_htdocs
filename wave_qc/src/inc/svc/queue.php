<?php
/**
 * Queue Service
 *
 * Actions can be inserted into a table for later execution. This service object
 * will actually perform the required actions, deleting all entries that were
 * successfully executed
 *
 * @package Services
 * @subpackage Queue
 * @copyright Copyright (c) 2004-2009 QBF GmbH (http://www.qbf.de)
 */

class CInc_Svc_Queue extends CSvc_Base {

  protected $mRequest;

  protected function doExecute() {
    $lSql = 'SELECT COUNT(*) FROM al_sys_queue;';
    $lCount = CCor_Qry::getInt($lSql);

    $lSql = 'SELECT * FROM al_sys_queue ORDER BY create_date ASC;';
    $lQry = new CCor_Qry($lSql);

    $lMandanten = array();

    $lReq = new CCor_Req();
    $lReq->loadRequest();

    $lNum = 1;
    foreach($lQry as $lRow){
      $lFnc = 'act' . $lRow['act'];
      if ($this->hasMethod($lFnc)) {
        $this->progressTick($lRow['act'].' ('.$lNum.' of '.$lCount.')');
        try {
          $this->setParam($lRow['params']);
          $lMid = $this->getParam('mid');
          if (0 < MID AND MID == $lMid) {// only execute items for current mand
            $lRes = $this->$lFnc();
            if ($lRes){
              CCor_Qry::exec('DELETE FROM al_sys_queue WHERE id=' . esc($lRow['id']));
            } else {
              $this->msg('Queued Command ID '.$lRow['id']. ' (' . $lRow['act'] . ') failed: '.$lRow['params'], mtAdmin, mlWarn);
            }
          }
        } catch(Exception $lExc){
          $this->msg($lExc->getMessage(), mtAdmin, mlError);
        }
      }
      $lNum++;
    }
    return TRUE;
  }

  protected function setParam($aParams) {
    $this->mPar = toArr($aParams);
  }

  protected function getParam($aKey, $aStd = NULL) {
    return (isset($this->mPar[$aKey])) ? $this->mPar[$aKey] : $aStd;
  }

  /**
   * Create a Webcenter user from our local user database
   *
   * @return boolean Successful?
   */
  protected function actWecusr() {
    $lUid = $this->getParam('uid');
    $lMid = $this->getParam('mid');

    $lWec = new CApi_Wec_Client();
    $lWec -> loadConfig();
    $lQry = new CApi_Wec_Query_Createuser($lWec);
    $lRes = $lQry->createFromDb($lUid);
    if ($lRes !== False) {
      $lCfg = CCor_Cfg::getInstance();
      $lQry = new CApi_Wec_Query_Addusertogroup($lWec);
      $lRes1 = $lQry -> createFromDb($lUid, $lCfg -> getVal('wec.grp'));
    }
    return $lRes;
  }

  /**
   * Invite a user as an approver to a Webcenter project
   * Deprecated! Use the Webcenter template for user access rights instead
   *
   * @return boolean Successful?
   */
  protected function actWecinvite() {
    $lUid = $this->getParam('uid');
    $lPid = $this->getParam('prj');

    $lWec = new CApi_Wec_Client();
    $lWec->loadConfig();

    $lInf = new CCor_Usr_Info($lUid);
    $lWecUid = $lInf->get('wec_uid');
    if(empty($lWecUid)){
      $lQry = new CApi_Wec_Query_Createuser($lWec);
      $lRes = $lQry->createFromDb($lUid);
      if (!$lRes) {
        return false;
      }
      $lWecUid = $lRes;
    }
    $lQry2 = new CApi_Wec_Query_Invite($lWec);
    $lRet = $lQry2->add($lWecUid, $lPid);
    return $lRet;
  }

  /**
   * Remove a user from a Webcenter project
   * Deprecated! Use the Webcenter template for user access rights instead
   *
   * @return boolean Successful?
   */
  protected function actWecremove() {
    $lUid = $this->getParam('uid');
    $lPid = $this->getParam('prj');

    $lWec = new CApi_Wec_Client();
    $lWec->loadConfig();

    $lInf = new CCor_Usr_Info($lUid);
    $lWecUid = $lInf->get('wec_uid');
    if (empty($lWecUid)) {
      return TRUE;
    }
    $lQry2 = new CApi_Wec_Query_Invite($lWec);
    $lRet = $lQry2->remove($lWecUid, $lPid);
    return $lRet;
  }

  /**
   * Retrieve the Webcenter history for a whole project, iterating over the
   * files of that project. Store new comments and status changes in the
   * history and, if applicable, in the approval loop as well.
   *
   * @return boolean Successful?
   */
  protected function actWechistory() {
    $lSrc = $this->getParam('src');
    $lJid = $this->getParam('jid');
    $lMid = $this->getParam('mid');

    // $lAnnotsByApi = true; // Kommentar aus Annotationen zusammensetzen
    $lAnnotsByApi = false; // Kommentar nicht aus Annotationen zusammensetzen

    $lApl = new CApp_Apl_Loop($lSrc, $lJid, 'apl', $lMid);

    // Get WebStatus form Archive OR Networker in which Status the Job is. .
    // $lSql = 'SELECT wec_prj_id FROM al_job_shadow_'.$lMid.' WHERE jobid='.esc($lJid);
    // $lWecPid = CCor_Qry::getStr($lSql);
    $lWecPid = $this->getWebcenterId($lJid, $lSrc);

    if (empty($lWecPid)) {
      return FALSE;
    }
    $lDebug = FALSE;
    $lDownloadAnnotations = TRUE;
    $lUpd = new CApi_Wec_Updatehistory($lSrc, $lJid, $lWecPid, $lDebug, $lDownloadAnnotations);

    // load hashes from local history to prevent importing an item twice
    $lHisArr = array();
    $lSql = 'SELECT add_data FROM al_job_his WHERE 1 ';
    $lSql .= 'AND src=' . esc($lSrc) . ' ';
    $lSql .= 'AND mand=' . esc($lMid) . ' ';
    $lSql .= 'AND src_id=' . esc($lJid);
    $lQry = new CCor_Qry($lSql);
    foreach($lQry as $lRow){
      $lAdd = $lRow['add_data'];
      if(!empty($lAdd)){
        $lAddArr = unserialize($lAdd);
        if(!empty($lAddArr['hash'])){
          $lHisArr[] = $lAddArr['hash'];
        }
      }
    }

    $lIgn = 0;
    $lImp = 0;
    $lArr = $lUpd->getHistoryArray();
    $lHis = new CJob_His($lSrc, $lJid);

    if (!empty($lArr))
    foreach ($lArr as $lRow) {
      // item already imported?
      $lHash = CApi_Wec_Query_History::getItemHash($lRow);
      if(in_array($lHash, $lHisArr)){
        $lIgn++;
        continue;
      }

      if(isset($lRow['userid'])){
        $lUid = $lRow['userid'];
      }else{
        $lUid = $lUpd->mapUser($lRow['uid']);
      }
      #echo ($lRow['uid'].' = '.$lUid.LF);
      $lHis->setUser($lUid);
      $lHis->setDate($lRow['date']);
      if($lAnnotsByApi){
        $lCom = $lApl->getCurrentUserComment($lUid);
        $lCom = cat($lCom, $lRow['comment'], LF . LF);
      }else{
        $lCom = NULL;
      }
      $lSub = lan('wec.comment');
      $lTyp = $lRow['typ'];

      switch($lTyp){
        case htAplOk:
          $lSub = lan('apl.approval');
          $lApl->setState($lUid, CApp_Apl_Loop::APL_STATE_APPROVED, $lCom, NULL, true);
          break;
        case htAplNok:
          $lSub = lan('apl.amendment');
          $lApl->setState($lUid, CApp_Apl_Loop::APL_STATE_AMENDMENT, $lCom, NULL, true);
          break;
        case htAplCond:
          $lSub = lan('apl.conditional');
          $lApl->setState($lUid, CApp_Apl_Loop::APL_STATE_CONDITIONAL, $lCom, NULL, true);
          break;
      }
      $lAdd = array('hash' => $lHash);
      $lHis->add($lRow['typ'], $lSub, $lRow['comment'], $lAdd);
      if(htWecComment == $lRow['typ']){
        if($lAnnotsByApi){
          $lApl->addToComment($lUid, $lRow['comment']);
        }
      }
      $lImp++;
    }
    #echo $lImp.' imported, '.$lIgn.' ignored';
    $lCli = new CApi_Wec_Robot();
    $lCli->loadConfig();
    $lCli->logout();
    return TRUE;
  }

  /**
   * Create a Webcenter project. On success, save the Webcenter project ID in
   * the job and add another queued command to activate the new project
   *
   * @return boolean Successful?
   */
  protected function actWecprj() {
    $lJid = $this -> getParam('jid');
    $lSrc = $this -> getParam('src');

    $lDraft = stristr($lJid, 'A') ? TRUE : FALSE;

    $lSql = new CCor_TblIte('al_job_arc_'.MID);
    $lSql -> addCnd('jobid='.esc($lJid));
    $lSql -> getIterator();
    $lRes = $lSql -> getDat();

    $lArchived = $lRes ? TRUE : FALSE;

    if (!$lDraft AND !$lArchived) {
      $lWec = new CApp_Wec($lSrc, $lJid);
      $lWecPrjId = $lWec -> createWebcenterProject();
    }

    return TRUE;
  }

  protected function actWecthumb() {
    $lJobId = $this -> getParam('jobid');
    $lSrc = $this -> getParam('src');

    $lDraft = stristr($lJobId, 'A') ? TRUE : FALSE;

    $lSql = new CCor_TblIte('al_job_arc_'.MID);
    $lSql -> addCnd('jobid='.esc($lJobId));
    $lSql -> getIterator();
    $lRes = $lSql -> getDat();

    $lArchived = $lRes ? TRUE : FALSE;

    if (!$lDraft AND !$lArchived) {
      $lRet = CInc_Svc_Wectns::downloadImage($lJobId, $lSrc);
    }

    return TRUE;
  }

  /**
   * Activate a given Webcenter project. An approval cycle can only be started
   * on active projects. Do this right after creating the project.
   *
   * @return boolean Successful?
   */
  protected function actWecactprj() {
    $lPid = $this->getParam('pid');

    $lCli = new CApi_Wec_Robot();
    $lCli->loadConfig();
    if (!$lCli -> login()) {
      return false;
    }
    $lCli->startProject($lPid);
    return TRUE;
  }

  /**
   * Start the approval cycle for all files in the Webcenter project
   *
   * @return boolean Successful?
   */
  protected function actWecstart() {
    $lSrc = $this->getParam('src');
    $lJid = $this->getParam('jid');

    // Get WebStatus form Archive OR Networker in which Status the Job is. .
    // $lSql = 'SELECT wec_prj_id FROM al_job_shadow_'.$lMid.' WHERE jobid='.esc($lJid);
    // $lWecPid = CCor_Qry::getStr($lSql);
    $lWecPid = $this->getWebcenterId($lJid, $lSrc);

    if(empty($lWecPid)) return false;

    $lWec = new CApi_Wec_Client();
    $lWec->loadConfig();

    $lQry = new CApi_Wec_Query_Doclist($lWec);
    $lArr = $lQry->getList($lWecPid);

    if(empty($lArr)) return TRUE;
    $lRobot = new CApi_Wec_Robot();
    $lRobot->login();

    $lRet = TRUE;
    foreach($lArr as $lRow){
      $lDoc = $lRow['wec_ver_id'];
      $lRes = $lRobot->startApl($lDoc);
      if (!$lRes) {
        $lRet = FALSE;
        // do not break; here - try the other files...
      }
    }
    return $lRet;
  }

  /**
   * Stop the approval cycle for all files in the Webcenter project
   * Deprecated! Use wecapprove or wecreject instead
   *
   * @return boolean Successful?
   */
  protected function actWecstop() {
    $lSrc = $this->getParam('src');
    $lJid = $this->getParam('jid');

    // Get WebStatus form Archive OR Networker in which Status the Job is. .
    // $lSql = 'SELECT wec_prj_id FROM al_job_shadow_'.$lMid.' WHERE jobid='.esc($lJid);
    // $lWecPid = CCor_Qry::getStr($lSql);
    $lWecPid = $this->getWebcenterId($lJid, $lSrc);

    if(empty($lWecPid)) return false;

    $lWec = new CApi_Wec_Client();
    $lWec->loadConfig();

    $lQry = new CApi_Wec_Query_Doclist($lWec);
    $lArr = $lQry->getList($lWecPid);

    if(empty($lArr)) return TRUE;
    $lRobot = new CApi_Wec_Robot();
    $lRobot->login();

    $lRet = TRUE;
    foreach($lArr as $lRow){
      $lDoc = $lRow['wec_ver_id'];
      $lRes = $lRobot->stopApl($lDoc);
      if(!$lRes){
        $lRet = FALSE;
        // do not break; here - try the other files...
      }
    }
    return $lRet;
  }

  /**
   * Set the statis of all files for a Webcenter project to forced approval.
   *
   * @return boolean Successful?
   */
  protected function actWecapprove() {
    $lSrc = $this->getParam('src');
    $lJid = $this->getParam('jid');

    // Get WebStatus form Archive OR Networker in which Status the Job is. .
    // $lSql = 'SELECT wec_prj_id FROM al_job_shadow_'.$lMid.' WHERE jobid='.esc($lJid);
    // $lWecPid = CCor_Qry::getStr($lSql);
    $lWecPid = $this->getWebcenterId($lJid, $lSrc);

    if(empty($lWecPid)) return false;

    $lWec = new CApi_Wec_Client();
    $lWec->loadConfig();

    $lQry = new CApi_Wec_Query_Doclist($lWec);
    $lArr = $lQry->getList($lWecPid);

    if(empty($lArr)) return TRUE;
    $lRobot = new CApi_Wec_Robot();
    $lRobot->login();

    $lRet = TRUE;
    foreach($lArr as $lRow){
      $lDoc = $lRow['wec_ver_id'];
      $lRes = $lRobot->forcedApproval($lWecPid, $lDoc);
      if(!$lRes){
        $lRet = FALSE;
        // do not break; here - try the other files...
      }
    }
    return true;
  }

  /**
   * Set the statis of all files for a Webcenter project to forced rejection
   *
   * @return boolean Successful?
   */
  protected function actWecreject() {
    $lSrc = $this->getParam('src');
    $lJid = $this->getParam('jid');

    // Get WebStatus form Archive OR Networker in which Status the Job is. .
    // $lSql = 'SELECT wec_prj_id FROM al_job_shadow_'.$lMid.' WHERE jobid='.esc($lJid);
    // $lWecPid = CCor_Qry::getStr($lSql);
    $lWecPid = $this->getWebcenterId($lJid, $lSrc);

    if(empty($lWecPid)) return false;

    $lWec = new CApi_Wec_Client();
    $lWec->loadConfig();

    $lQry = new CApi_Wec_Query_Doclist($lWec);
    $lArr = $lQry->getList($lWecPid);

    if(empty($lArr)) return TRUE;
    $lRobot = new CApi_Wec_Robot();
    $lRobot->login();

    $lRet = TRUE;
    foreach($lArr as $lRow){
      $lDoc = $lRow['wec_ver_id'];
      $lRes = $lRobot->forcedRejection($lWecPid, $lDoc);
      if(!$lRes){
        $lRet = FALSE;
        // do not break; here - try the other files...
      }
    }

    return true;
  }

  protected function getWebcenterId($aJobId, $aSrc) {
    $lJobId = $aJobId;
    $lSrc = $aSrc;

    $lSql = 'SELECT COUNT(*) FROM al_job_arc_' . MID . ' WHERE jobid=' . esc($lJobId);
    $lCnt = CCor_Qry::getInt($lSql);

    if(0 < $lCnt){ // Job is archive. Get WebcenterId from ArchiveTabelle.
      $lClass = 'CArc_Dat';
      $this->dbg('Webcenter Projekt Id from ArchiveTabelle');
      $lJob = new $lClass($lSrc);
      $lJob->load($lJobId);
      $lRet = $lJob['wec_prj_id'];
      return $lRet;
    } else { // Job is active. Get WebcenterId from Networker/Mop/Wave DB.
      $this->dbg('Webcenter Projekt Id from Networker/Mop/Wave DB');
      $lFac = new CJob_Fac($lSrc, $lJobId);
      $lJob = $lFac -> getDat();
      $lRet = $lJob['wec_prj_id'];
      return $lRet;
    }
  }

  protected function actDalimthumb() {
    $lDoc = $this->getParam('doc');
    $lJid = $this->getParam('jid');

    $lRet = false;
    $lUtil = new CApi_Dalim_Utils();
    $lSvcWecInst = CSvc_Wec::getInstance();

    $lImg = $lUtil->getThumbnail($lDoc);
    if($lImg){
      $lDynamics = $lSvcWecInst->getDynamics($lJid);
      $lFilename = $lDynamics['thumbnail_dir'] . $lDynamics['thumbnail_file'];
      @mkdir($lDynamics['thumbnail_dir'], 0755, true);
      $lCount = file_put_contents($lFilename, $lImg);
      if($lCount !== false){
        $lRet = true;
      }
    }
    $lImg = $lUtil->getLowRes($lDoc);
    if($lImg){
      $lDynamics = $lSvcWecInst->getDynamics($lJid);
      $lFilename = $lDynamics['image_dir'] . $lDynamics['image_file'];
      @mkdir($lDynamics['image_dir'], 0755, true);
      $lCount = file_put_contents($lFilename, $lImg);
      if($lCount !== false){
        $lRet = true;
      }
    }
    return $lRet;
  }

  protected function actWait() {
    sleep(10);
    return false;
  }

  /**
   * Create a *.CSV file
   *
   * @return boolean Successful?
   */
  protected function actCreateCsv() {
    $lUId = $this->getParam('uid'); // user id
    $lMId = $this->getParam('mid'); // mandator id
    $lAge = $this->getParam('age'); // either >job< or >arc<
    $lSrc = $this->getParam('src'); // job type: art, rep, etc.
    $lFil = unserialize(base64_decode($this->getParam('fil'))); // filter
    $lSer = unserialize(base64_decode($this->getParam('ser'))); // search

    // User
    $lAnyUsr = new CCor_Anyusr($lUId);
    $lAnyUsrAnrede = $lAnyUsr->getVal('anrede');
    $lAnyUsrFirstname = $lAnyUsr->getVal('firstname');
    $lAnyUsrLastname = $lAnyUsr->getVal('lastname');
    $lAnyUsrEmail = $lAnyUsr->getVal('email');
    $lAnyUsr->loadPrefsFromDb(); // load anyusr prefs explicitly
    $lAnyUsr->setPref('sys.mid', $lMId); // set mand/mid explicitly
    $lAnyUsrLanguage = $lAnyUsr->getPref('sys.lang', 'en');

    // Mandator
    $lMand = CCor_Qry::getStr('SELECT code FROM al_sys_mand WHERE id='.$lMId);

    // Filename
    $lMandArray = CCor_Res::extract('code', 'name_'.$lAnyUsrLanguage, 'mand');
    $lMandName = str_replace(' ', '_', $lMandArray[MAND]);

    $lFileName = lang($lAge.'-'.$lSrc.'.menu', $lAnyUsrLanguage);
    $lFileName .= '_';
    $lFileName .= $lMandName;
    $lFileName .= '_';
    $lFileName .= date('Ymd_H-i-s');
    $lFileName .= '.csv';

    // JobFields
    $lJobFieldById = array();
    $lQry = new CCor_Qry('SELECT id,mand,src,alias,native,name_en,desc_en,desc_de,name_de,typ,param,attr,feature,learn,avail,flags,used FROM al_fie WHERE mand='.$lMId.' ORDER BY alias;');
    foreach($lQry as $lRow){
      $lJobFieldById[$lRow['id']] = array(
        'id' => $lRow['id'],
        'mand' => $lRow['mand'],
        'src' => $lRow['src'],
        'alias' => $lRow['alias'],
        'native' => $lRow['native'],
        'name_en' => $lRow['name_en'],
        'desc_en' => $lRow['desc_en'],
        'desc_de' => $lRow['desc_de'],
        'name_de' => $lRow['name_de'],
        'typ' => $lRow['typ'],
        'param' => $lRow['param'],
        'attr' => $lRow['attr'],
        'feature' => $lRow['feature'],
        'learn' => $lRow['learn'],
        'avail' => $lRow['avail'],
        'flags' => $lRow['flags'],
        'used' => $lRow['used']
      );
    }

    $lConfig = array(
      'uid' => $lUId,
      'mid' => $lMId,
      'age' => $lAge,
      'src' => $lSrc,
      'fil' => $lFil,
      'ser' => $lSer,
      'anyusr' => $lAnyUsr,
      'anyusrlanguage' => $lAnyUsrLanguage,
      'mand' => $lMand,
      'filename' => $lFileName,
      'jobfieldbyid' => $lJobFieldById,
      'destination' => 'csv'
    );

    $lWriter = CCor_Cfg::get('job.writer.default', 'alink');
    if ('portal' == $lWriter OR 'arc' == $lAge) {
      $this -> getViaPDB($lConfig);
    } else {
      $this -> getViaAlink($lConfig);
    }
    
    // Create zip
    $lZip = new ZipArchive();
    if ($lZip -> open(getcwd().'/tmp/'.$lFileName.'.zip', ZIPARCHIVE::CREATE) === TRUE) {
      $lZip -> addFile(getcwd().'/tmp/'.$lFileName, $lFileName);
      $lZip -> close();
    }

    // Create mail
    $lTplId = CCor_Cfg::get('csv-exp.tpl');
    if (!empty($lTplId)) {
      $lTpl = new CApp_Tpl();
      if (is_int($lTplId)) {
        $lTpl -> loadTemplate($lTplId);
      } else {
        $lTpl -> loadTemplate(0, $lTplId, LAN);
      }

      $lTpl -> setPat('to.anrede', $lAnyUsrAnrede, '');
      $lTpl -> setPat('to.firstname', $lAnyUsrFirstname, '');
      $lTpl -> setPat('to.lastname', $lAnyUsrLastname, '');

      $lFromFirstname = CCor_Cfg::get('svc.firstname', '');
      $lFromLastname = CCor_Cfg::get('svc.lastname', '');
      $lFromEmail = CCor_Cfg::get('svc.email', '');
      $lFromPhone = CCor_Cfg::get('svc.phone', '');

      $lTpl -> setPat('from.firstname', $lFromFirstname, '');
      $lTpl -> setPat('from.lastname', $lFromLastname, '');
      $lTpl -> setPat('from.email', $lFromEmail, '');
      $lTpl -> setPat('from.phone', $lFromPhone, '');

      $lSubject = $lTpl -> getSubject();
      $lBody = $lTpl -> getBody();

      $lMail = new CApi_Mail_Item($lFromEmail, $lFromFirstname.' '.$lFromLastname, $lAnyUsrEmail, $lAnyUsrFirstname.' '.$lAnyUsrLastname, $lSubject, $lBody);
      if (file_exists(getcwd().'/tmp/'.$lFileName.'.zip')) {
        $lMail -> addAttachFile(getcwd().'/tmp/'.$lFileName.'.zip');
      } elseif (file_exists(getcwd().'/tmp/'.$lFileName)) {
        $lMail -> addAttachFile(getcwd().'/tmp/'.$lFileName);
      }
      $lMail -> setReciverId($lUId);
      $lMail -> setJobSrc($lSrc);
      $lMail -> setMailType(mailSys);
      $lMail -> insert(FALSE, $lMId);
    }

    // Clean up
    unlink(getcwd().'/tmp/'.$lFileName.'.zip');
    unlink(getcwd().'/tmp/'.$lFileName);

    return true;
  }

  /**
   * Create a *.XLS file
   *
   * @return boolean Successful?
   */
  protected function actCreateXls() {
    $lUId = $this->getParam('uid'); // user id
    $lMId = $this->getParam('mid'); // mandator id
    $lAge = $this->getParam('age'); // either >job< or >arc<
    $lSrc = $this->getParam('src'); // job type: art, rep, etc.
    $lFil = unserialize(base64_decode($this->getParam('fil'))); // filter
    $lSer = unserialize(base64_decode($this->getParam('ser'))); // search

    // User
    $lAnyUsr = new CCor_Anyusr($lUId);
    $lAnyUsrAnrede = $lAnyUsr->getVal('anrede');
    $lAnyUsrFirstname = $lAnyUsr->getVal('firstname');
    $lAnyUsrLastname = $lAnyUsr->getVal('lastname');
    $lAnyUsrEmail = $lAnyUsr->getVal('email');
    $lAnyUsr->loadPrefsFromDb(); // load anyusr prefs explicitly
    $lAnyUsr->setPref('sys.mid', $lMId); // set mand/mid explicitly
    $lAnyUsrLanguage = $lAnyUsr->getPref('sys.lang', 'en');

    // Mandator
    $lMand = CCor_Qry::getStr('SELECT code FROM al_sys_mand WHERE id='.$lMId);

    // Filename
    $lMandArray = CCor_Res::extract('code', 'name_' . $lAnyUsrLanguage, 'mand');
    $lMandName = str_replace(' ', '_', $lMandArray[MAND]);

    $lFileName = lang($lAge . '-' . $lSrc . '.menu', $lAnyUsrLanguage);
    $lFileName .= '_';
    $lFileName .= $lMandName;
    $lFileName .= '_';
    $lFileName .= date('Ymd_H-i-s');
    $lCsvFileName = $lFileName . '.csv';
    $lXlsFileName = $lFileName . '.xls';

    // JobFields
    $lJobFieldById = array();
    $lQry = new CCor_Qry('SELECT id,mand,src,alias,native,name_en,desc_en,desc_de,name_de,typ,param,attr,feature,learn,avail,flags,used FROM al_fie WHERE mand='.$lMId.' ORDER BY alias;');
    foreach($lQry as $lRow){
      $lJobFieldById[$lRow['id']] = array(
        'id' => $lRow['id'],
        'mand' => $lRow['mand'],
        'src' => $lRow['src'],
        'alias' => $lRow['alias'],
        'native' => $lRow['native'],
        'name_en' => $lRow['name_en'],
        'desc_en' => $lRow['desc_en'],
        'desc_de' => $lRow['desc_de'],
        'name_de' => $lRow['name_de'],
        'typ' => $lRow['typ'],
        'param' => $lRow['param'],
        'attr' => $lRow['attr'],
        'feature' => $lRow['feature'],
        'learn' => $lRow['learn'],
        'avail' => $lRow['avail'],
        'flags' => $lRow['flags'],
        'used' => $lRow['used']
      );
    }

    $lConfig = array(
      'uid' => $lUId,
      'mid' => $lMId,
      'age' => $lAge,
      'src' => $lSrc,
      'fil' => $lFil,
      'ser' => $lSer,
      'anyusr' => $lAnyUsr,
      'anyusrlanguage' => $lAnyUsrLanguage,
      'mand' => $lMand,
      'filename' => $lCsvFileName,
      'jobfieldbyid' => $lJobFieldById,
      'destination' => 'xls'
    );


    $lWriter = CCor_Cfg::get('job.writer.default', 'alink');
    if ('portal' == $lWriter OR 'arc' == $lAge) {
      $this -> getViaPDB($lConfig);
    } else {
      $this -> getViaAlink($lConfig);
    }

    // PHPExcel
    $lSeparator = CCor_Cfg::get('csv-exp.separator', ';');

    // $lCacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
    // $lCacheSettings = array('memoryCacheSize' => '2GB');
    // PHPExcel_Settings::setCacheStorageMethod($lCacheMethod, $lCacheSettings);

    require_once 'Office/PHPExcel/IOFactory.php';
    $lPHPExcelReader = PHPExcel_IOFactory::createReader('CSV');
    $lPHPExcelReader->setReadDataOnly(true);
    $lPHPExcelReader->setDelimiter($lSeparator);
    $lPHPExcelReader->setInputEncoding('ISO-8859-1');
    // $lPHPExcelReader -> setInputEncoding('UTF-8');
    $lPHPExcel = $lPHPExcelReader->load(getcwd() . '/tmp/' . $lCsvFileName);
    $lPHPExcelWriter = PHPExcel_IOFactory::createWriter($lPHPExcel, 'Excel5');
    $lPHPExcelWriter->save(getcwd() . '/tmp/' . $lXlsFileName);

    // Create zip
    $lZip = new ZipArchive();
    if ($lZip -> open(getcwd().'/tmp/'.$lXlsFileName.'.zip', ZIPARCHIVE::CREATE) === TRUE) {
      $lZip -> addFile(getcwd().'/tmp/'.$lXlsFileName, $lXlsFileName);
      $lZip -> close();
    }

    // Create mail
    $lTplId = CCor_Cfg::get('csv-exp.tpl');
    if (!empty($lTplId)) {
      $lTpl = new CApp_Tpl();
      if (is_int($lTplId)) {
        $lTpl->loadTemplate($lTplId);
      } else {
        $lTpl->loadTemplate(0, $lTplId, LAN);
      }

      $lTpl -> setPat('to.anrede', $lAnyUsrAnrede, '');
      $lTpl -> setPat('to.firstname', $lAnyUsrFirstname, '');
      $lTpl -> setPat('to.lastname', $lAnyUsrLastname, '');

      $lFromFirstname = CCor_Cfg::get('svc.firstname', '');
      $lFromLastname = CCor_Cfg::get('svc.lastname', '');
      $lFromEmail = CCor_Cfg::get('svc.email', '');
      $lFromPhone = CCor_Cfg::get('svc.phone', '');

      $lTpl -> setPat('from.firstname', $lFromFirstname, '');
      $lTpl -> setPat('from.lastname', $lFromLastname, '');
      $lTpl -> setPat('from.email', $lFromEmail, '');
      $lTpl -> setPat('from.phone', $lFromPhone, '');

      $lSubject = $lTpl -> getSubject();
      $lBody = $lTpl -> getBody();

      $lMail = new CApi_Mail_Item($lFromEmail, $lFromFirstname.' '.$lFromLastname, $lAnyUsrEmail, $lAnyUsrFirstname.' '.$lAnyUsrLastname, $lSubject, $lBody);
      if (file_exists(getcwd().'/tmp/'.$lXlsFileName.'.zip')) {
        $lMail -> addAttachFile(getcwd().'/tmp/'.$lXlsFileName.'.zip');
      } elseif (file_exists(getcwd().'/tmp/'.$lXlsFileName)) {
        $lMail -> addAttachFile(getcwd().'/tmp/'.$lXlsFileName);
      }
      $lMail -> setReciverId($lUId);
      $lMail -> setJobSrc($lSrc);
      $lMail -> setMailType(mailSys);
      $lMail -> insert(FALSE, $lMId);
    }

    // Clean up
    unlink(getcwd().'/tmp/'.$lXlsFileName.'.zip');
    unlink(getcwd().'/tmp/'.$lXlsFileName);
    unlink(getcwd().'/tmp/'.$lCsvFileName);

    return true;
  }

  /**
   * Create a *.CSV file for reporting timings
   *
   * @return boolean Successful?
   */
  protected function actCreateRep() {
    $lUId = $this->getParam('uid'); // user id
    $lMId = $this->getParam('mid'); // mandator id
    $lAge = $this->getParam('age'); // either >job< or >arc<
    $lSrc = $this->getParam('src'); // job type: art, rep, etc.
    $lFil = unserialize(base64_decode($this->getParam('fil'))); // filter
    $lSer = unserialize(base64_decode($this->getParam('ser'))); // search

    // User
    $lAnyUsr = new CCor_Anyusr($lUId);
    $lAnyUsrAnrede = $lAnyUsr->getVal('anrede');
    $lAnyUsrFirstname = $lAnyUsr->getVal('firstname');
    $lAnyUsrLastname = $lAnyUsr->getVal('lastname');
    $lAnyUsrEmail = $lAnyUsr->getVal('email');
    $lAnyUsr->loadPrefsFromDb(); // load anyusr prefs explicitly
    $lAnyUsr->setPref('sys.mid', $lMId); // set mand/mid explicitly
    $lAnyUsrLanguage = $lAnyUsr->getPref('sys.lang', 'en');

    // Mandator
    $lMand = CCor_Qry::getStr('SELECT code FROM al_sys_mand WHERE id='.$lMId);

    // Filename
    $lMandArray = CCor_Res::extract('code', 'name_'.$lAnyUsrLanguage, 'mand');
    $lMandName = str_replace(' ', '_', $lMandArray[MAND]);

    $lFileName = lang($lAge.'-'.$lSrc.'.menu', $lAnyUsrLanguage);
    $lFileName .= '_';
    $lFileName .= $lMandName;
    $lFileName .= '_TimingReport_';
    $lFileName .= date('Ymd_H-i-s');
    $lFileName .= '.csv';

    // JobFields
    $lJobFieldById = array();
    $lQry = new CCor_Qry('SELECT id,mand,src,alias,native,name_en,desc_en,desc_de,name_de,typ,param,attr,feature,learn,avail,flags,used FROM al_fie WHERE mand='.$lMId.' ORDER BY alias;');
    foreach($lQry as $lRow){
      $lJobFieldById[$lRow['id']] = array(
        'id' => $lRow['id'],
        'mand' => $lRow['mand'],
        'src' => $lRow['src'],
        'alias' => $lRow['alias'],
        'native' => $lRow['native'],
        'name_en' => $lRow['name_en'],
        'desc_en' => $lRow['desc_en'],
        'desc_de' => $lRow['desc_de'],
        'name_de' => $lRow['name_de'],
        'typ' => $lRow['typ'],
        'param' => $lRow['param'],
        'attr' => $lRow['attr'],
        'feature' => $lRow['feature'],
        'learn' => $lRow['learn'],
        'avail' => $lRow['avail'],
        'flags' => $lRow['flags'],
        'used' => $lRow['used']
      );
    }

    $lConfig = array(
      'uid' => $lUId,
      'mid' => $lMId,
      'age' => $lAge,
      'src' => $lSrc,
      'fil' => $lFil,
      'ser' => $lSer,
      'anyusr' => $lAnyUsr,
      'anyusrlanguage' => $lAnyUsrLanguage,
      'mand' => $lMand,
      'filename' => $lFileName,
      'jobfieldbyid' => $lJobFieldById,
      'destination' => 'csv'
    );

    $this->getRpt($lConfig);

    // Create zip
    $lZip = new ZipArchive();
    if ($lZip -> open(getcwd().'/tmp/'.$lFileName.'.zip', ZIPARCHIVE::CREATE) === TRUE) {
      $lZip -> addFile(getcwd().'/tmp/'.$lFileName, $lFileName);
      $lZip -> close();
    }

    // Create mail
    $lTplId = CCor_Cfg::get('rep-exp.tpl');
    if (!empty($lTplId)) {
      $lTpl = new CApp_Tpl();
      if (is_int($lTplId)) {
        $lTpl -> loadTemplate($lTplId);
      } else {
        $lTpl -> loadTemplate(0, $lTplId, LAN);
      }

      $lTpl -> setPat('to.anrede', $lAnyUsrAnrede, '');
      $lTpl -> setPat('to.firstname', $lAnyUsrFirstname, '');
      $lTpl -> setPat('to.lastname', $lAnyUsrLastname, '');

      $lFromFirstname = CCor_Cfg::get('svc.firstname', '');
      $lFromLastname = CCor_Cfg::get('svc.lastname', '');
      $lFromEmail = CCor_Cfg::get('svc.email', '');
      $lFromPhone = CCor_Cfg::get('svc.phone', '');

      $lTpl -> setPat('from.firstname', $lFromFirstname, '');
      $lTpl -> setPat('from.lastname', $lFromLastname, '');
      $lTpl -> setPat('from.email', $lFromEmail, '');
      $lTpl -> setPat('from.phone', $lFromPhone, '');

      $lSubject = $lTpl -> getSubject();
      $lBody = $lTpl -> getBody();

      $lMail = new CApi_Mail_Item($lFromEmail, $lFromFirstname.' '.$lFromLastname, $lAnyUsrEmail, $lAnyUsrFirstname.' '.$lAnyUsrLastname, $lSubject, $lBody);
      if (file_exists(getcwd().'/tmp/'.$lFileName.'.zip')) {
        $lMail -> addAttachFile(getcwd().'/tmp/'.$lFileName.'.zip');
      } elseif (file_exists(getcwd().'/tmp/'.$lFileName)) {
        $lMail -> addAttachFile(getcwd().'/tmp/'.$lFileName);
      }
      $lMail -> setReciverId($lUId);
      $lMail -> setJobSrc($lSrc);
      $lMail -> setMailType(mailSys);
      $lMail -> insert(FALSE, $lMId);
    }

    // Clean up
    unlink(getcwd().'/tmp/'.$lFileName.'.zip');
    unlink(getcwd().'/tmp/'.$lFileName);

    return true;
  }

  private function getRows($aRequest, $aResponse, $aLan, $aSrc) {
    $lRequest = $aRequest;
    $lResponse = $aResponse;
    $lLan = $aLan;

    $lFlagsArr = CCor_Res::extract('val', 'name_' . $lLan, 'jfl');
    $lCrp = CCor_Res::extract('code', 'id', 'crpmaster');

    foreach($lResponse as $this->mRow){
      $lCell = '';
      $lRow = '';

      foreach($lRequest->mCols as $this->mColKey => & $this->mCol){
        $this->mCurCol = & $lRequest->mCols[$this->mColKey];
        $lTyp = $this->mCurCol->getFieldAttr('typ');
        $lCell = '';

        if($this->mColKey == 'flags'){ // flags
          $lFlags = (isset($this->mRow[$this->mColKey])) ? intval($this->mRow[$this->mColKey]) : 0;
          foreach($lFlagsArr as $lFlagsKey => $lFlagsValue){
            if(bitSet($lFlags, $lFlagsKey)){
              $lCell = $lFlagsValue;
              break;
            }
          }
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }elseif($this->mColKey == 'mand'){ // mand
          $lMand = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          if(empty($lMand)){
            $lCell = "Global";
          }elseif($lVal == -1){
            $lCell = lan('lib.mand.all');
          }else{
            $lArr = CCor_Res::extract('id', 'name_' . LAN, 'mand');
            $lCell = $lArr[$lVal];
          }
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }elseif($this->mColKey == 'src'){ // src
          $lSrc = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          $lCell = lan('job-' . $lSrc . '.menu');
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }elseif($this->mColKey == 'webstatus'){ // webstatus
          $lCrpId = ($aSrc != 'all') ? $lCrp[$aSrc] : $lCrp[ $this -> mRow['src'] ];
          $lCrpArr = CCor_Res::extract('status', 'name_' . $lLan, 'crpstatus', $lCrpId);
      
          $lWebstatus = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          $lCell = $lWebstatus / 10;
          $lRow .= '"' . utf8_decode($lCell) . '";"' . utf8_decode($lCrpArr[$lWebstatus]) . '";';
        }elseif($lTyp == 'boolean'){ // boolean
          $lBoolean = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          $lCell = ('X' == $lBoolean) ? 'X' : '';
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }elseif($lTyp == 'date'){ // date
          $lDate = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          $lDat = new CCor_Date($lDate);
          $lCell = $lDat->getFmt(lan('lib.date.long'));
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }elseif($lTyp == 'gselect'){ // gselect
          $lGSelect = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          $lArr = CCor_Res::extract('id', 'name', 'gru');
          if(isset($lArr[$lGSelect])){
            $lCell = $lArr[$lGSelect];
          }else{
            $lCell = '';
          }
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }elseif($lTyp == 'memo'){ // memo
          $lMemo = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          $lCell = $lMemo;
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }elseif($lTyp == 'tselect'){ // tselect
          $lTSelect = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          $lPar = toArr($this->mCurCol->getFieldAttr('param'));
          $lTbl = $lPar['dom'];
          $lArr = CCor_Res::get('htb', $lTbl);
          if(isset($lArr[$lTSelect])){
            $lCell = $lArr[$lTSelect];
          }else{
            $lCell = $lTSelect;
          }
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }elseif($lTyp == 'uselect'){ // uselect
          $lUSelect = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          if(empty($lUSelect)){
            $lCell = '';
          }
          $lArr = CCor_Res::extract('id', 'fullname', 'usr');
          if(isset($lArr[$lUSelect])){
            $lCell = $lArr[$lUSelect];
          }else{
            $lCell = '';
          }
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }else{
          $lElse = (isset($this->mRow[$this->mColKey])) ? $this->mRow[$this->mColKey] : '';
          $lCell = $lElse;
          $lRow .= '"' . utf8_decode($lCell) . '";';
        }
      }

      $lRow .= CR . LF;
      $lRes .= $lRow;
    }

    return $lRes;
  }

  private function getViaPDB4CreateCSVonly($aConfig) {
    $lUId = $aConfig['uid'];
    $lMId = $aConfig['mid'];
    $lAge = $aConfig['age'];
    $lSrc = $aConfig['src'];
    $lFil = $aConfig['fil']; // TODO: delete?
    $lSer = $aConfig['ser']; // TODO: delete?
    $lAnyUsr = $aConfig['anyusr'];
    $lAnyUsrLanguage = $aConfig['anyusrlanguage']; // TODO: delete?
    $lFileName = $aConfig['filename'];
    $lJobFieldById = $aConfig['jobfieldbyid']; // TODO: delete?
    $lDestination = $aConfig['destination']; // TODO: delete?

    $lNewCols = array();

    // Columns only
    $lCols = $lAnyUsr -> getPref($lAge.'-'.$lSrc.'.cols');
    if (empty($lCols)) {
      $lSql = 'SELECT val FROM al_sys_pref WHERE code = "'.$lAge.'-'.$lSrc.'.cols'.'" AND mand='.$lMId;
      $lCols = CCor_Qry::getArrImp($lSql);
    }
    
    $lCols = explode(',', $lCols);
    if (count($lCols) > 0) {
      foreach ($lCols as $lKey => $lValue) {
        if ($lJobFieldById[$lValue]['typ'] != 'file' && $lJobFieldById[$lValue]['typ'] != 'hidden' && $lJobFieldById[$lValue]['typ'] != 'image') {
          $lNewCols[$lJobFieldById[$lValue]['alias']] = new CHtm_Column($lJobFieldById[$lValue]['alias'], $lJobFieldById[$lValue]['name_'.$lAnyUsrLanguage], FALSE, null, $lJobFieldById[$lValue]);
        }
      }
    }

    // Header
    foreach ($lNewCols as $lKey => & $lValue) {
      $lTyp = $lValue -> getFieldAttr('typ');
      $lAlias = $lValue -> getFieldAttr('alias');
    
      if ($lTyp != 'file' && $lTyp != 'hidden' && $lTyp != 'image') {
        if ($lAlias == 'webstatus') {
          $lHeader .= '"Status";"Status Description";';
        } else {
          $lHeader .= '"'.$lValue -> getCaption().'";';
        }
      }
    }

    file_put_contents(getcwd().'/tmp/'.$lFileName, $lHeader.CR.LF, FILE_APPEND);

    // Content
    $lClass_List = 'C'.ucfirst($lAge).'_'.ucfirst($lSrc).'_List';
    $lWithoutLimit = FALSE;
    $lJobList = new $lClass_List($lWithoutLimit, $lAnyUsr -> getId());

    $lIdField = $lJobList -> mIdField;
    $lDummy = NULL;
    $lCounter = 0;
    $lContercounter = $lJobList -> mIte -> getCount();
    while ($lContercounter > 0) {
      if (!is_null($lDummy)) {
        $lJobList -> mIte = $lDummy;
      }
      $lJobList -> mIte -> setLimit($lCounter, 50);
      $lDummy = $lJobList -> mIte;

      $lJobList -> mIte = $lJobList -> mIte -> getArray($lIdField);
      $lContercounter = count($lJobList -> mIte);
      $lJobList -> loadFlags();

      $lRet = $lJobList -> getCsvContent(NULL, NULL, TRUE);
      file_put_contents(getcwd().'/tmp/'.$lFileName, $lRet, FILE_APPEND);

      $lCounter+=50;
    }
  }

  private function getViaPDB($aConfig) {
    $lUId = $aConfig['uid'];
    $lMId = $aConfig['mid'];
    $lAge = $aConfig['age'];
    $lSrc = $aConfig['src'];
    $lFil = $aConfig['fil'];
    $lSer = $aConfig['ser'];
    $lAnyUsr = $aConfig['anyusr'];
    $lAnyUsrLanguage = $aConfig['anyusrlanguage'];
    $lFileName = $aConfig['filename'];
    $lJobFieldById = $aConfig['jobfieldbyid'];
    $lDestination = $aConfig['destination'];

    // Archive only
    if ($lAge == 'arc') {
      $lRequest = new CCor_TblIte('al_job_arc_'.$lMId, FALSE);
    }

    if ($lAge == 'job' && $lSrc != 'all') {
      $lRequest = new CCor_TblIte('al_job_'.$lSrc.'_'.$lMId, FALSE);
    } elseif  ($lAge == 'job' && $lSrc == 'all') {
      $lMinStatus = CCor_TblIte::getInt('SELECT MIN(`status`) FROM al_crp_master AS `master`, al_crp_status AS `status` WHERE `master`.id=`status`.crp_id AND `master`.mand='.MID);

      $lRequest = new CCor_TblIte('all', $this -> mWithoutLimit);
      $lRequest -> addField('jobid');
      $lRequest -> addCondition('webstatus', '>=', $lMinStatus);
    }

    // Columns only
    $lCols = $lAnyUsr -> getPref($lAge.'-'.$lSrc.'.cols');
    if (empty($lCols)) {
      $lSql = 'SELECT val FROM al_sys_pref WHERE code = "'.$lAge.'-'.$lSrc.'.cols'.'" AND mand='.$lMId;
      $lCols = CCor_Qry::getArrImp($lSql);
    }

    $lCols = explode(',', $lCols);
    if (count($lCols) > 0) {
      foreach ($lCols as $lKey => $lValue) {
        if ($lJobFieldById[$lValue]['typ'] != 'file' && $lJobFieldById[$lValue]['typ'] != 'hidden' && $lJobFieldById[$lValue]['typ'] != 'image') {
          $lRequest -> mCols[$lJobFieldById[$lValue]['alias']] = new CHtm_Column($lJobFieldById[$lValue]['alias'], $lJobFieldById[$lValue]['name_'.$lAnyUsrLanguage], FALSE, null, $lJobFieldById[$lValue]);

          $lRequest -> addField($lJobFieldById[$lValue]['alias']);
        }
      }
    }

    // User and group conditions
    $lRequest -> addUserConditions($lUId);
    $lRequest -> addGroupConditions($lUId);

    $lWebStatusFilter = FALSE;

    // Filter only
    if (is_array($lFil) && !empty($lFil) && !is_null($lFil)) {
      foreach ($lFil as $lFilKey => $lFilValue) {
        if (!empty($lFilValue)) {
          if (is_array($lFilValue) AND $lFilKey == "webstatus") {
            $lStates = "";

            foreach ($lFilValue as $lWebstatusKey => $lWebstatusValue) {
              if ($lWebstatusKey == 0) {
                break;
              } else {
                $lStates.= '"'.$lWebstatusKey.'",';
              }
            }

            if (!empty($lStates)) {
              $lStates = substr($lStates, 0, -1);
              $lRequest -> addField('webstatus');
              $lRequest -> addCondition('webstatus', 'IN', $lStates);

              $lWebStatusFilter = TRUE;
            }
          } elseif (is_array($lFilValue) AND $lFilKey == "flags") {
            $lFlagsStates = "";

            foreach ($lFilValue as $lFlagsKey => $lFlagsValue) {
              if ($lFlagsKey == 0) {
                break;
              } else {
                $lFlagsStates.= "((flags & ".$lFlagsKey.") = ".$lFlagsKey.") OR ";
              }
            }
            $lFlagsStates = (!empty($lFlagsStates)) ? substr($lFlagsStates, 0, strlen($lFlagsStates) - 4) : '';

            if (!empty($lFlagsStates)) {
              $lJobIds = '';
              $lSQL = 'SELECT jobid FROM al_job_shadow_'.MID.' WHERE '.$lFlagsStates.';';

              $lQry = new CCor_Qry($lSQL);
              foreach ($lQry as $lRow) {
                $lJobId = trim($lRow['jobid']);
                if (!empty($lJobId)) {
                  $lJobIds.= '"'.$lJobId.'",';
                }
              }
              $lJobIds = strip($lJobIds);

              if (!empty($lJobIds)) {
                $lRequest -> addCondition('jobid', 'IN', $lJobIds);
              } else {
                $lRequest -> addCondition('jobid', '=', 'NOJOBSFOUND');
              }
            }
          } else {
            $lRequest -> addField($lFilKey);
            $lRequest -> addCondition($lFilKey, '=', $lFilValue);
          }
        }
      }
    }

    // Search only
    $lCond = new CCor_Cond();
    if (is_array($lSer) && !empty($lSer) && !is_null($lSer)) {
      foreach ($lSer as $lAli => $lVal) {
        if (empty($lVal)) continue;
        if (!isset($lRequest -> mDefs[$lAli])) continue;
  
        $lDef = $lRequest -> mDefs[$lAli];
        $lCnd = $lCond -> convert($lAli, $lVal, $lDef['typ']);
        if ($lCnd) {
          foreach ($lCnd as $lItm) {
            $lRequest -> addField($lItm['field']);
            $lRequest -> addCondition($lItm['field'], $lItm['op'], $lItm['value']);
          }
        }
      }
    }

    // Handle >all< job tpye
    if ($lSrc != 'all') {
      $lRequest -> addCondition('src', '=', $lSrc); // WHERE
    } elseif ($lAge == 'job') {
      $lAvaSrc = '"'.implode('","', CCor_Cfg::get('all-jobs')).'"';
      $lRequest -> addCondition('src', 'IN', $lAvaSrc); // WHERE
    }

    $lCRPID = CCor_TblIte::getInt('SELECT id FROM al_crp_master WHERE mand='.MID.' AND code="'.$lSrc.'";');
    $lWebStatusArray = CCor_Res::extract('id', 'status', 'crpstatus', $lCRPID);
    if (count($lWebStatusArray) > 0 AND !$lWebStatusFilter) {
      $lWebStatusImplode = implode(',', $lWebStatusArray);
      $lRequest -> addField('webstatus');
      $lRequest -> addCondition('webstatus', 'IN', $lWebStatusImplode);
    }

    // JobList: setOrder
    $lOrd = $lAnyUsr -> getPref($lAge.'-'.$lSrc.'.ord', 'jobnr');
    $lDir = 'asc';
    if (substr($lOrd, 0, 1) == '-') {
      $lOrd = substr($lOrd, 1);
      $lDir = 'desc';
    }

    $lRequest -> setOrder($lOrd, $lDir);

    // JobList: setLimit
    $lMaxLines = $lRequest -> getCount();
    $lLpp = 50; // reduced from 200 to 50 to reduce memory usage per cycle
    $lPages = ceil($lMaxLines / $lLpp);

    // Header
    if ($lDestination == 'csv') {
      $lSeparator = CCor_Cfg::get('csv-exp.separator', ';');
      $lHeader = "sep=".$lSeparator.CR.LF;
    } else {
      $lHeader = '';
    }

    foreach ($lRequest -> mCols as $lKey => & $lValue) {
      $lTyp = $lValue -> getFieldAttr('typ');
      $lAlias = $lValue -> getFieldAttr('alias');

      if ($lTyp != 'file' && $lTyp != 'hidden' && $lTyp != 'image') {
        if ($lAlias == 'webstatus') {
          $lHeader .= '"Status";"Status Description";';
        } else {
          $lHeader .= '"'.$lValue->getCaption().'";';
        }
      }
    }

    file_put_contents(getcwd().'/tmp/'.$lFileName, $lHeader.CR.LF, FILE_APPEND);

    // Language
    if (isset($lAnyUsrLanguage)) {
      $lLan = $lAnyUsrLanguage;
    } else {
      $lLan = LAN;
    }

    $lPage = 0;
    while ($lPage * $lLpp <= $lMaxLines) {
      $lRequest -> setLimit($lPage * $lLpp, $lLpp);
      $lResponse = $lRequest -> getArray();
      $lRes = $this -> getRows($lRequest, $lResponse, $lLan, $lSrc);

      file_put_contents(getcwd().'/tmp/'.$lFileName, $lRes, FILE_APPEND);
      $lPage++;
    }

    return true;
  }

  private function getViaAlink($aConfig) {
    $lUId = $aConfig['uid'];
    $lMId = $aConfig['mid'];
    $lAge = $aConfig['age'];
    $lSrc = $aConfig['src'];
    $lFil = $aConfig['fil'];
    $lSer = $aConfig['ser'];
    $lAnyUsr = $aConfig['anyusr'];
    $lAnyUsrLanguage = $aConfig['anyusrlanguage'];
    $lMand = $aConfig['mand'];
    $lFileName = $aConfig['filename'];
    $lJobFieldById = $aConfig['jobfieldbyid'];
    $lDestination = $aConfig['destination'];

    // Language
    if(isset($lAnyUsrLanguage)){
      $lLan = $lAnyUsrLanguage;
    }else{
      $lLan = LAN;
    }

    $lActive = $lHeaders = TRUE;
    $lPage = $lCount = 0;

    // Columns only
    $lCols = $lAnyUsr->getPref($lAge . '-' . $lSrc . '.cols');
    if(empty($lCols)){
      $lSql = 'SELECT val FROM al_sys_pref WHERE code = "' . $lAge . '-' . $lSrc . '.cols' . '" AND mand=' . $lMId;
      $lCols = CCor_Qry::getArrImp($lSql);
    }
    $lCols = explode(',', $lCols);

    while($lActive == TRUE){
      $lRequest = new CApi_Alink_Query_Getjoblist('', false, $lMand);

      if(count($lCols) > 0){
        foreach($lCols as $lKey => $lValue){
          if($lJobFieldById[$lValue]['typ'] != 'file' && $lJobFieldById[$lValue]['typ'] != 'hidden' && $lJobFieldById[$lValue]['typ'] != 'image' && !empty($lJobFieldById[$lValue]['native'])){
            $lRequest->mCols[$lJobFieldById[$lValue]['alias']] = new CHtm_Column($lJobFieldById[$lValue]['alias'], $lJobFieldById[$lValue]['name_' . $lAnyUsrLanguage], false, null, $lJobFieldById[$lValue]);

            $lRequest->addField($lJobFieldById[$lValue]['alias'], $lJobFieldById[$lValue]['native']);
          }
        }
      }

      // User and group conditiona
      $lRequest->addUserConditions($lUId);
      $lRequest->addGroupConditions($lUId);

      // Filter only
      if (is_array($lFil) && !empty($lFil) && !is_null($lFil)) {
        foreach ($lFil as $lKey => $lValue) {
          if (!empty($lValue)) {
            if (is_array($lValue) AND $lKey == "webstatus") {
              $lStates = "";
  
              foreach ($lValue as $lWebstatus => $foo) {
                if ($lWebstatus == 0) {
                  break;
                } else {
                  $lStates.= '"'.$lWebstatus.'",';
                }
              }
  
              if (!empty($lStates)) {
                $lStates = substr($lStates, 0, -1);
  
                $lRequest -> addCondition('webstatus', 'IN', $lStates);
              }
            } elseif (is_array($lValue) AND $lKey == "flags") {
              $lStates = "";
  
              foreach ($lValue as $lKey => $lValue) {
                if($lKey == 0){
                  break;
                } else {
                  $lStates.= "((flags & ".$lKey.") = ".$lKey.") OR ";
                }
              }
              $lStates = (!empty($lStates)) ? substr($lStates, 0, strlen($lStates) - 4) : '';
  
              if (!empty($lStates)) {
                $lJobIds = '';
                $lSQL = 'SELECT jobid FROM al_job_shadow_'.MID.' WHERE '.$lStates.';';
                $lQry = new CCor_Qry($lSQL);
                foreach ($lQry as $lRow) {
                  $lJobId = trim($lRow['jobid']);
                  if (!empty($lJobId)) {
                    $lJobIds.= '"'.$lJobId.'",';
                  }
                }
                $lJobIds = strip($lJobIds);
  
                if (!empty($lJobIds)) {
                  $lRequest -> addCondition('jobid', 'IN', $lJobIds);
                } else {
                  $lRequest -> addCondition('jobid', '=', 'NOJOBSFOUND');
                }
              }
            } else {
              $lRequest -> addCondition($lKey, '=', $lValue);
            }
          }
        }
      }
        
      // Search only
      $lCond = new CCor_Cond();
      if (is_array($lSer) && !empty($lSer) && !is_null($lSer)) {
        foreach ($lSer as $lAli => $lVal) {
          if(empty($lVal)) continue;
          if(!isset($lRequest->mDefs[$lAli])) continue;
  
          $lDef = $lRequest->mDefs[$lAli];
          $lCnd = $lCond->convert($lAli, $lVal, $lDef['typ']);
          if($lCnd){
            foreach($lCnd as $lItm){
              $lRequest->addCondition($lItm['field'], $lItm['op'], $lItm['value']);
            }
          }
        }
      }

      // Handle >all< job tpye
      if($lSrc != 'all'){
        $lRequest->addCondition('src', '=', $lSrc); // WHERE
      }elseif($lAge == 'job'){
        $lAvaSrc = '"' . implode('","', CCor_Cfg::get('all-jobs')) . '"';
        $lRequest->addCondition('src', 'IN', $lAvaSrc); // WHERE
      }

      // JobList: setOrder
      $lOrd = $lAnyUsr->getPref($lAge . '-' . $lSrc . '.ord', 'jobnr');
      $lDir = 'asc';
      if(substr($lOrd, 0, 1) == '-'){
        $lOrd = substr($lOrd, 1);
        $lDir = 'desc';
      }

      $lRequest->setOrder($lOrd, $lDir);

      // JobList: setLimit
      $lLpp = 200;

      // Header
      if($lPage == 0 && $lHeaders === TRUE){
        if($lDestination == 'csv'){
          $lSeparator = CCor_Cfg::get('csv-exp.separator', ';');
          $lHeader = "sep=" . $lSeparator . CR . LF;
        }else{
          $lHeader = '';
        }
        foreach($lRequest->mCols as $lKey => & $lValue){
          $lTyp = $lValue->getFieldAttr('typ');
          $lAlias = $lValue->getFieldAttr('alias');

          if($lTyp != 'file' && $lTyp != 'hidden' && $lTyp != 'image'){
            if($lAlias == 'webstatus'){
              $lHeader .= '"Status";"Status Description";';
            }else{
              $lHeader .= '"' . $lValue->getCaption() . '";';
            }
          }
        }

        file_put_contents(getcwd() . '/tmp/' . $lFileName, $lHeader . CR . LF, FILE_APPEND);
        $lHeaders = FALSE;
      }

      $lIdField = $lRequest->mIdField;
      $lSection = intval($lPage * $lLpp);

      $lRequest->setLimit($lSection, $lLpp);
      $lResponse = $lRequest->getArray($lIdField);
      $lReqCount = $lRequest->getCount();
      $lCount = ($lReqCount > 0 ? intval($lReqCount) : 0);
      
      if(empty($lResponse)){
        if($lSection > $lCount) {
          $lActive = FALSE;
        }
      }else{
        $lRes = $this->getRows($lRequest, $lResponse, $lLan, $lSrc);

        file_put_contents(getcwd() . '/tmp/' . $lFileName, $lRes, FILE_APPEND);
        $lPage++;
      }

      unset($lRequest);
    }

    return true;
  }

  private function getReportingRows($aResponse, $aCols, $aAdds, $aFields, $aMId) {    
    $lRes = '';
    $lJobids = array();
    foreach($aResponse as $this->mRow) {
      $lWriter = CCor_Cfg::get('job.writer.default', 'alink');
      $lExt = ('alink' == $lWriter) ? '000' : ''; //only add zeros to front of jobnr if alink is used
      $lJobids[] = isset($this->mRow['jobid']) ? esc($this->mRow['jobid']) : esc($lExt . $this->mRow['jobnr']);
    }

    $lQry = new CCor_Qry('SELECT ' . implode(",", $aFields) . ' FROM al_job_shadow_' . $aMId . '_report WHERE jobid IN (' . implode(",", $lJobids) . ') ORDER BY jobid,id ASC;');
    foreach($lQry as $lRows){
      $lRow = '"' . $lRows['jobid'] . '";"' . $lRows['row_id'] . '";';

      foreach($aAdds as $lKey => $lVal){
        $lRow .= rtrim($lRows[$lKey], ";") . ";";
      }

      foreach($aCols as $lKey => $lVal){
        $lLti = $lFti = FALSE;
        /*if($lRows['lti_cr_' . $lVal] !== '0000-00-00 00:00:00'){
          $lRow .= $lRows['lti_cr_' . $lVal] . ";";
          $lLti = TRUE;
        }*/

        if($lRows['fti_cr_' . $lVal] !== '0000-00-00 00:00:00' && $lLti === FALSE){
          $lRow .= $lRows['fti_cr_' . $lVal] . ";";
          $lFti = TRUE;
        }

        if($lLti === FALSE && $lFti === FALSE){
          $lRow .= ";";
        }
      }
      $lRow .= CR . LF;
      $lRes .= $lRow;
    }

    return $lRes;
  }

  private function getRpt($aConfig) {
    $lUId = $aConfig['uid'];
    $lMId = $aConfig['mid'];
    $lAge = $aConfig['age'];
    $lSrc = $aConfig['src'];
    $lFil = $aConfig['fil'];
    $lSer = $aConfig['ser'];
    $lAnyUsr = $aConfig['anyusr'];
    $lAnyUsrLanguage = $aConfig['anyusrlanguage'];
    $lMand = $aConfig['mand'];
    $lFileName = $aConfig['filename'];
    $lJobFieldById = $aConfig['jobfieldbyid'];
    $lDestination = $aConfig['destination'];

    // Language
    $lLan = (isset($lAnyUsrLanguage)) ? $lAnyUsrLanguage : LAN;
    $lPage = $lCount = 0;

    $lCols = $lAdds = array();
    $lQry = new CCor_Qry('SHOW COLUMNS FROM al_job_shadow_' . $lMId . '_report');
    foreach($lQry as $lRow){
      if(strpos($lRow['Field'], 'fti_cr_') !== FALSE){
        $lCols[] = str_replace('fti_cr_', '', $lRow['Field']);
      }
    }
    $lRepAdd = CCor_Cfg::get('rep-exp.add', array());
    foreach($lRepAdd as $lAdd){
      $lSql = 'SELECT name_en FROM al_fie WHERE alias= "'.$lAdd.'" AND mand=' . $lMId;
      $lAdds[$lAdd] = CCor_Qry::getStr($lSql);
    }

    $lFields = array( 'jobid', 'row_id' );
    foreach($lAdds as $lKey => $lVal){
      $lFields[] = $lKey;
    }
    foreach($lCols as $lKey => $lVal){
      $lFields[] = 'fti_cr_' . $lVal;
      $lFields[] = 'lti_cr_' . $lVal;
    }

    // Header
    if($lPage === 0){
      if($lDestination == 'csv'){
        $lSeparator = CCor_Cfg::get('csv-exp.separator', ';');
        $lHeader = "sep=" . $lSeparator . CR . LF;
      }else{
        $lHeader = '';
      }

      $lMapper = CCor_Cfg::get('report.map');
      $lHeader .= '"JobNr";"Row";';
      foreach($lAdds as $lKey => $lVal){
        $lHeader .= '"'. $lVal . '";';
      }
      foreach($lCols as $lKey => $lVal){
        $lKey = array_search($lVal, $lMapper);
        $lHeader .= '"' . $lKey . '";';
      }

      file_put_contents(getcwd() . '/tmp/' . $lFileName, $lHeader . CR . LF, FILE_APPEND);
    }

    if($lAge === 'job'){
      $lActive = TRUE;

      while ($lActive == TRUE) {
        $lWriter = CCor_Cfg::get('job.writer.default', 'alink');
        if ('portal' == $lWriter) {
          $this -> mRequest = new CCor_TblIte('all', FALSE);
        } else {
          $this -> mRequest = new CApi_Alink_Query_Getjoblist('', FALSE, $lMand);
        }

        $this->setupRequest($aConfig);

        $lLpp = 25;
        $lIdField = $this->mRequest->mIdField;
        $lSection = intval($lPage * $lLpp);
        $this->mRequest->setLimit($lSection, $lLpp);
        $lResponse = $this->mRequest->getArray($lIdField);

        $lReqCount = $this->mRequest->getCount();
	    $lCount = ($lReqCount > 0) ? intval($lReqCount) : 0;
        
        if(empty($lResponse)){
          if ($lSection > $lCount) {
            $lActive = FALSE;
          }
        }else{
          $lRes = $this->getReportingRows($lResponse, $lCols, $lAdds, $lFields, $lMId);
          file_put_contents(getcwd() . '/tmp/' . $lFileName, $lRes, FILE_APPEND);
          $lPage++;
        }

        unset($this -> mRequest);
      }
    }else{
      $this->mRequest = new CCor_TblIte('al_job_arc_' . $lMId, false);
      $this->setupRequest($aConfig);
      $lMaxLines = $this->mRequest->getCount();
      $lLpp = 25;

      while($lPage * $lLpp <= $lMaxLines){
        $this->mRequest->setLimit($lPage * $lLpp, $lLpp);
        $lResponse = $this->mRequest->getArray();

        $lRes = $this->getReportingRows($lResponse, $lCols, $lAdds, $lFields, $lMId);
        file_put_contents(getcwd() . '/tmp/' . $lFileName, $lRes, FILE_APPEND);
        $lPage++;
      }
    }

    return true;
  }

  private function setupRequest($aConfig) {
    $lUId = $aConfig['uid'];
    $lMId = $aConfig['mid'];
    $lAge = $aConfig['age'];
    $lSrc = $aConfig['src'];
    $lFil = $aConfig['fil'];
    $lSer = $aConfig['ser'];
    $lAnyUsr = $aConfig['anyusr'];
    $lAnyUsrLanguage = $aConfig['anyusrlanguage'];
    $lJobFieldById = $aConfig['jobfieldbyid'];
    $lWriter = CCor_Cfg::get('job.writer.default', 'alink');

    // Columns only
    $lCols = $lAnyUsr->getPref($lAge . '-' . $lSrc . '.cols');
    if(empty($lCols)){
      $lSql = 'SELECT val FROM al_sys_pref WHERE code = "' . $lAge . '-' . $lSrc . '.cols' . '" AND mand=' . $lMId;
      $lCols = CCor_Qry::getArrImp($lSql);
    }
    $lCols = explode(',', $lCols);
    if(count($lCols) > 0){
      foreach($lCols as $lKey => $lValue){
        if($lJobFieldById[$lValue]['typ'] != 'file' && $lJobFieldById[$lValue]['typ'] != 'hidden' && $lJobFieldById[$lValue]['typ'] != 'image' && !empty($lJobFieldById[$lValue]['native'])){
          $this->mRequest->mCols[$lJobFieldById[$lValue]['alias']] = new CHtm_Column($lJobFieldById[$lValue]['alias'], $lJobFieldById[$lValue]['name_' . $lAnyUsrLanguage], false, null, $lJobFieldById[$lValue]);
          if($lAge === 'job'){
            $this->mRequest->addField($lJobFieldById[$lValue]['alias'], $lJobFieldById[$lValue]['native']);
          }else{
            $this->mRequest->addField($lJobFieldById[$lValue]['alias']);
          }
        }
      }
    }

    if(($lAge == 'arc' || $lWriter == 'portal') && !array_key_exists('jobid', $this->mRequest->mCols)){
      $this->mRequest->addField('jobid');
    }

    // User and group conditiona
    $this->mRequest->addUserConditions($lUId);
    $this->mRequest->addGroupConditions($lUId);

    // Filter only
    if(is_array($lFil) && !empty($lFil) && !is_null($lFil)) {
      foreach($lFil as $lKey => $lValue){
        if(!empty($lValue)){
          if(is_array($lValue) and $lKey == "webstatus"){
            $lStates = "";
  
            foreach($lValue as $lWebstatus => $foo){
              if($lWebstatus == 0){
                break;
              }else{
                $lStates .= '"' . $lWebstatus . '",';
              }
            }
  
            if(!empty($lStates)){
              $lStates = substr($lStates, 0, -1);
              if($lAge === 'arc' || $lWriter == 'portal'){
                $this->mRequest->addField('webstatus');
              }
              $this->mRequest->addCondition('webstatus', 'IN', $lStates);
            }
          } elseif (is_array($lValue) AND $lKey == "flags") {
            $lStates = "";

            foreach ($lValue as $lKey => $lValue) {
              if($lKey == 0){
                break;
              } else {
                $lStates.= "((flags & ".$lKey.") = ".$lKey.") OR ";
              }
            }
            $lStates = (!empty($lStates)) ? substr($lStates, 0, strlen($lStates) - 4) : '';

            if (!empty($lStates)) {
              $lJobIds = '';
              $lSQL = 'SELECT jobid FROM al_job_shadow_'.MID.' WHERE '.$lStates.';';
              $lQry = new CCor_Qry($lSQL);
              foreach ($lQry as $lRow) {
                $lJobId = trim($lRow['jobid']);
                if (!empty($lJobId)) {
                  $lJobIds.= '"'.$lJobId.'",';
                }
              }
              $lJobIds = strip($lJobIds);

              if (!empty($lJobIds)) {
                $this->mRequest->addCondition('jobid', 'IN', $lJobIds);
              } else {
                $this->mRequest->addCondition('jobid', '=', 'NOJOBSFOUND');
              }
            }
          } else{
            if($lAge === 'arc' || $lWriter == 'portal'){
              $this->mRequest->addField($lKey);
            }
            $this->mRequest->addCondition($lKey, '=', $lValue);
          }
        }
      }
    }

    // Search only
    $lCond = new CCor_Cond();
    if(is_array($lSer) && !empty($lSer) && !is_null($lSer)) {
      foreach($lSer as $lAli => $lVal){
        if(empty($lVal)) continue;
        if(!isset($this->mRequest->mDefs[$lAli])) continue;
  
        $lDef = $this->mRequest->mDefs[$lAli];
        $lCnd = $lCond->convert($lAli, $lVal, $lDef['typ']);
        if($lCnd){
          foreach($lCnd as $lItm){
            if($lAge === 'arc' || $lWriter == 'portal'){
              $this->mRequest->addField($lItm['field']);
            }
            $this->mRequest->addCondition($lItm['field'], $lItm['op'], $lItm['value']);
          }
        }
      }
    }

    // Handle >all< job type
    if($lSrc != 'all'){
      $this->mRequest->addCondition('src', '=', $lSrc); // WHERE
    }elseif($lAge == 'job'){
      $lAvaSrc = '"' . implode('","', CCor_Cfg::get('all-jobs')) . '"';
      $this->mRequest->addCondition('src', 'IN', $lAvaSrc); // WHERE
    }

    // JobList: setOrder
    $lOrd = $lAnyUsr->getPref($lAge . '-' . $lSrc . '.ord', 'jobnr');
    $lDir = 'asc';
    if(substr($lOrd, 0, 1) == '-'){
      $lOrd = substr($lOrd, 1);
      $lDir = 'desc';
    }

    $this->mRequest->setOrder($lOrd, $lDir);
  }
  
  protected function actCopydmstocountry() {
    // source   
    $lJid  = $this->getParam('jid');
    $lSrc  = $this->getParam('src');
    $lFn   = $this->getParam('fn');
    $lVerId  = $this->getParam('vid');
    $lAuthor = $this->getParam('author');
    
    // target
    $lFid  = $this->getParam('fid');
    $lSid  = $this->getParam('sid');
    $lCountry  = $this->getParam('country');
    
    // upload
    $lQry = new CApi_Dms_Query();
    $lRes = $lQry -> uploadFile($lTemp, $lOldName, $lAuthor, MANDATOR_ENVIRONMENT, $this -> mSrc, $this -> mJid);
    if (!$lRes) {
      return false;
    }
    $lNewVid = $lRes['fileversionid'];

    $lQue = new CApp_Queue('copydmstodalim');
    $lQue->setParam('src', $lSrc);
    $lQue->setParam('jid', $lJid);
    $lQue->setParam('vid', $lNewVid);
    $lQue->setParam('dfn', $lJid.'_'.$lCountry);
    $lQue->setParam('sid', $lSid);
    $lQue->insert();
    
  }
  
  protected function actCopydmstodalim() {
    // source
    $lSrc = $this->getParam('src');
    $lJid = $this->getParam('jid');
    
    $lVid = $this->getParam('vid');
    $lSrcFn  = $this->getParam('fn');
        
    // target
    $lPref  = $this->getParam('prefix', '');
    $lSid = $this->getParam('sid');
    
    $lDir = CCor_Cfg::get('dms.pdf.folder', '/media/dmspdf/');
    
    $lPat = glob($lDir.$lVid.'_*.pdf');
    if (empty($lPat)) {
      return false;
    }
    $lSrcFile = $lPat[0];
    $lDestFn  = $lPref.$lJid.'.pdf'; 
        
    $lPar['prefix'] = $lPref;
    $lUpload = new CJob_Fil_Upload($lSrc, $lJid);
    $lRet = $lUpload->uploadToDalim($lSrcFile, $lDestFn, $lPar);
    if (!$lRet) {
      return false;
    }
    $lSql = 'UPDATE al_job_apl_subloop SET file_secondary='.esc($lJid.'/'.$lRet);
    $lSql.= ' WHERE id='.esc($lSid);
    CCor_Qry::exec($lSql);
    return true;
 }
}
