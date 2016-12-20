<?php
/**
 * Title
 *
 * Description
 *
 * @package    package
 * @copyright  Copyright (c) 2004-2009 QBF GmbH (http://www.qbf.de)
 */

class CInc_Job_Apl_Page_Menu extends CHtm_Menu {

  public function __construct($aSrc, $aJid, $aLnk = '') {
    parent::__construct(lan('job-apl.my'));

    $lUsr = CCor_Usr::getInstance();
    $lUid = $lUsr -> getId();
    if (empty($aLnk)) {
      $lStdLnk = 'index.php?act=job-apl-page&amp;jid=';
    } else {
      $lStdLnk = $aLnk;
    }

    $lCount     = '';
    $lAplJobIds = array();

    $lSql = 'SELECT DISTINCT l.jobid,l.ddl,l.src,l.id,s.status,s.comment, s.loop_id, s.user_id, s.pos, s.done, s.confirm';
    $lSql.= ' FROM al_job_apl_states s, al_job_apl_loop l';
    $lSql.= ' LEFT JOIN al_job_arc_'.MID.' a ON l.jobid = a.jobid';
    $lSql.= ' WHERE 1';
    $lSql.= ' AND l.mand='.intval(MID).' ';
//   $lSql.= ' AND s.user_id='.$lUid;
    $lSql.= ' AND s.loop_id=l.id';
    $lSql.= ' AND l.status='.esc(CApp_Apl_Loop::APL_LOOP_OPEN);
    $lSql.= ' AND s.typ LIKE "apl%"';
//    $lSql.= ' AND l.jobid="'.$aJid.'"';
    $lSql.= ' AND s.del != "Y"';
    $lSql.= ' AND a.jobid IS NULL';
    $lSql.= ' GROUP BY s.loop_id,s.user_id'; // Aussortieren von doppelte User-ID
    $lSql.= ' ORDER BY l.ddl';
    $lQryJobsInApl = new CCor_Qry($lSql);
    
    $lIds = array();
    $lNoDeny = array();
    $lJobsInApl = array();
    #$lMinPos = MAX_SEQUENCE; // Behelfsvorbelegung
    foreach ($lQryJobsInApl as $lRow) {
      
      $lJobId = $lRow['jobid'];
      if(!isset($lMinPos[$lJobId])) {
        $lMinPos[$lJobId] = MAX_SEQUENCE; // Behelfsvorbelegung
      }
      //brauche zur Anzeige distinct user_ids => können sich über backupuser-Fkt ändern und mehrfach vorkommen
      //Angezeigt werden muß die user_id mit der kleineren pos, da die agieren darf: $lSql.= ' ORDER BY pos';
      if (!isset($lIds[$lJobId][$lRow['user_id']])) {
        $lIds[$lJobId][$lRow['user_id']] = $lRow;
      }
      $lPos[$lJobId] = $lRow['pos'];
      if (0 == $lRow['status'] AND $lMinPos[$lJobId] > $lPos[$lJobId]) {
        $lMinPos[$lJobId] = $lPos[$lJobId];
      }
      if (("one" == $lRow['confirm'] AND "Y" == $lRow['done'] AND empty($lRow['comment'])) OR "-" == $lRow['done']) {//fuer eine Uebergangszeit, da es vorher "-" nicht gab.
      #if ("one" == $lRow['confirm'] AND "Y" == $lRow['done'] AND empty($lRow['comment'])) {
        $lNoDeny[$lJobId][$lRow['user_id']] = TRUE;
      }
    }

    $lShowAplBtnUntilConfirm = CCor_Cfg::get('job.apl.show.btn.untilconfirm');
    foreach ($lQryJobsInApl as $lRow) {
      $lJobId = $lRow['jobid'];

      // welche Funktionalität man will -> config: != oder <
      //$lMinPos != $lIds[$lUid]['pos'] zeigt die Buttons nur an, solange man keinen Button bestätigt hat!
      //$lMinPos < $lIds[$lUid]['pos'] zeigt die Buttons, sobald man an der Reihe ist und auch weiter: Korrekturmögl.
      if($lShowAplBtnUntilConfirm) {
        $lShow = !isset($lIds[$lJobId][$lUid]) OR $lMinPos[$lJobId] != $lIds[$lJobId][$lUid]['pos'];
      } else {
        $lShow = !isset($lIds[$lJobId][$lUid]) OR $lMinPos[$lJobId] < $lIds[$lJobId][$lUid]['pos'];
      }
      if (!isset($lIds[$lJobId][$lUid]) OR $lMinPos[$lJobId] < $lIds[$lJobId][$lUid]['pos'] OR isset($lNoDeny[$lJobId][$lUid])) continue;
      if (!isset($lAplJobIds[$lJobId])) {
        $lCount++;
        $lAplJobIds[$lJobId] = $lJobId;
        $lJobsInApl[$lJobId] = $lRow;
      }
    }

    if (empty($lAplJobIds)) return; // There is no Job in APL

    $lJobIdStr = array_map("esc", $lAplJobIds);//jedes Element wird ".mysql_escaped."
    $lJobIdStr = implode(',', $lJobIdStr);
    $this -> dump($lJobIdStr);

    // Popup Window Scroll Setting
    if ($lCount < 10){
      $this -> lScroll = $lCount * 30 ;
    }else {
      $this -> lScroll = 250;
    }

    $this -> mDefs = CCor_Res::getByKey('alias', 'fie');
    $this -> mCnd = new CCor_Cond();
    $lDat         = new CCor_Date();

    $lJobFlags    = array();
    $lSql  = 'SELECT jobid,flags FROM al_job_shadow_'.intval(MID).' WHERE jobid IN ('.$lJobIdStr.')';
    $lQryJobsFlags = new CCor_Qry($lSql);
    foreach ($lQryJobsFlags as $lRow) {
      $lJobFlags[$lRow['jobid']] = $lRow['flags'];
    }

    // get Which Jobfields are showed in Popup menü.
    $lAplJobInfos = array();
    
    $lWriter = CCor_Cfg::get('job.writer.default', 'alink');
    $lDefFie      = CCor_Res::extract('alias', 'native', 'fie');
    $lFreigabe    = CCor_Cfg::get('job.apl.freigabe');
    if ('portal' == $lWriter) {
      $lIte = new CCor_TblIte('all');
      foreach ($lFreigabe as $lFie) {
        if (isset($lDefFie[$lFie])) {
          $lIte -> addField($lFie);
        }
      }
      $lIte -> addField('src');
      $lIte -> addField('jobid');
      $lIte -> addField('webstatus');
      $lIte -> addField('status');
      $lIte -> addCondition('jobid', 'in', $lJobIdStr);
    } else {
      $lIte = new CApi_Alink_Query_Getjoblist();
      foreach ($lFreigabe as $lFie) {
        if (isset($lDefFie[$lFie])) {
          $lIte -> addField($lFie, $lDefFie[$lFie]);
        }
      }
      $lIte -> addField('src', $lDefFie['src']);
      $lIte -> addField('jobid', 'jobid');
      $lIte -> addField('webstatus', 'webstatus');
      $lIte -> addField('status', 'status');
      $lIte -> addCondition('jobid', 'in', $lJobIdStr);
    }

    foreach ($lIte as $lRow) {
      if (($lRow['status'] == 'RE') or ($lRow['status'] == 'RS') or ($lRow['status'] == 'G')) continue;
      if (empty($lRow['src'])) continue;
      if (isset($lJobFlags[$lRow['jobid']])) {
        $lFlag = $lJobFlags[$lRow['jobid']];
        if (bitset($lFlag, jfCancelled)) continue;
        $lAplJobInfos[$lRow['jobid']] = array();
        foreach ($lFreigabe as $lPar){
          $lAplJobInfos[$lRow['jobid']][]= $lRow[$lPar];
        }
      }
    }
    foreach ($lJobsInApl as $lRow) {
      $lJid = $lRow['jobid'];
      if (!isset($lAplJobInfos[$lJid])) continue;
      $lSrc = $lRow['src'];
      $lLnk = $lStdLnk.$lJid.'&amp;src='.$lSrc;
      $lImg = 'ico/16/'.LAN.'/job-'.$lRow['src'].'.gif';
      $lImgUserStatusVal = $lIds[$lJid][$lUid]['status'];
      $lImg2 = 'img/ico/16/flag-0'.$lImgUserStatusVal.'.gif';
      if (($lJid == $aJid) and ($lSrc == $aSrc)) {
        $lImg = 'ico/16/nav-next-lo.gif';
      }
      $lDat -> setSql($lRow['ddl']);
      $lCap = array();
      $lCap = $lAplJobInfos[$lJid];
      $lCap[] = $lDat->getFmt(lan('lib.date.long'));
      $lRet   = CApp_Apl_Loop::getAplCommitList($lRow['id'], $lLnk);

      $lAplTd = (THEME === 'default') ? '<td class="td2 ac">' : '';
      $lAplTd.= $lRet;
      $lAplTd.= (THEME === 'default') ? '</td>' : '';

      $this -> addItem($lLnk, $lCap, $lImg, '', $lImg2, $lAplTd);
    }
  }

  protected function getCont() {
    if (empty($this -> mItems)) {
      return '';
    }
    $lRet = $this -> getComment('start');
    $lRet.= '<div style="padding:6px;" id="'.$this -> mDiv.'">';

    $lRet.= '<a class="nav" id="'.$this -> mLnkId.'" href="javascript:Flow.Std.popMen(\''.$this -> mDivId.'\',\''.$this -> mLnkId.'\')">';
    if($this -> mHtm)
      $lRet.= htm($this -> mCaption);
    else
      $lRet.= '<b>'.$this -> mCaption.'</b>';
    $lRet.= '</a>';
    echo $This -> lScroll;
    $lRet.= (THEME === 'default') ? $this -> getMenuDiv($this->lScroll) : $this -> getMenuWaveDiv($this->lScroll);

    $lRet.= '</div>';
    $lRet.= $this -> getComment('end');
    return $lRet;
  }
}