<?php
/*
 * @name Bizuno ERP - Data folder upload script
 *
 * NOTICE OF LICENSE
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.TXT.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/OSL-3.0
 *
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade bizuno-migrate to newer
 * versions in the future. If you wish to customize bizuno-locale for your
 * needs please refer to http://www.phreesoft.com for more information.
 *
 * @name       Bizuno ERP
 * @author     Dave Premo, PhreeSoft <support@phreesoft.com>
 * @copyright  2008-2020, PhreeSoft, Inc.
 * @license    http://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * @version    4.x Last Update: 2020-12-08
 * @filesource /bizuno-migrate/modules/xfrData/admin.php
 */

namespace bizuno;

ini_set('memory_limit','2048M');

class xfrDataAdmin
{
    public  $moduleID    = 'xfrData';
    private $dirBackup   = 'backups/';
    private $total_steps = 17; // number of iterations to perform
    public  $lang        = ['title'=>'Data Import', 'description'=>'Helpful tool to upload your Bizuno data files and images.'];

    function __construct()
    {
        if (!@is_writable(BIZUNO_DATA)) { return msgAdd('Error: your business folder needs to be writable! I cannot reach it.'); }
    }

    /**
     * Hook to add a tab to convert from QuickBooks to Bizuno, extends /bizuno/api/impExpMain
     */
    public function impExpMain(&$layout)
    {
        if (!$security = validateSecurity('bizuno', 'backup', 4)) { return; }
        $layout['tabs']['tabImpExp']['divs'][$this->moduleID] = ['order'=>80,'label'=>$this->lang['title'], 'type'=>'html', 'html'=>'',
            'options'=>['href'=>"'".BIZUNO_AJAX."&bizRt=bizuno/api/migrateMgr&modID=$this->moduleID'"]];
    }

    /**
     *
     * @param type $layout
     * @return type
     */
    public function manager(&$layout=[])
    {
        if (!$security = validateSecurity('bizuno', 'backup', 4)) { return; }
        $upload_mb = min((int)(ini_get('upload_max_filesize')), (int)(ini_get('post_max_size')), (int)(ini_get('memory_limit')));
        $uploadHTML= "Select a Bizuno Data zip file to import: ".sprintf(lang('max_upload'), $upload_mb);
        $fields    = [
            'fldBizFile'=> ['order'=>50,'attr'=>['type'=>'file']],
            'btnStep0'  => ['order'=>60,'break'=>true,'events'=>['onClick'=>"jqBiz('body').addClass('loading'); jqBiz('#frmStep0').submit();"],'attr'=>['type'=>'button','value'=>lang('upload')]]];
        $jsReady   = "ajaxForm('frmStep0');";
        $divs      = ['step0' => ['order'=>20,'type'=>'panel','key'=>'step0','classes'=>['block33']]];
        $panels    = ['step0' => ['label'=>$this->lang['title'],'type'=>'divs','divs'=>[
            'desc'   => ['order'=>10,'type'=>'html',  'html'=>"<p>$uploadHTML</p>"],
            'formBOF'=> ['order'=>20,'type'=>'form',  'key' =>'frmStep0'],
            'body'   => ['order'=>30,'type'=>'fields','keys'=>['fldBizFile','btnStep0']],
            'formEOF'=> ['order'=>90,'type'=>'html',  'html'=>"</form>"]]]];
        $data = ['type'=>'divHTML', 'title'=>$this->lang['title'],
            'divs'   => ['manager' => ['order'=>50,'type'=>'divs','classes'=>['areaView'],'divs'=>$divs]],
            'panels' => $panels,
            'forms'  => ['frmStep0'=> ['attr'=>['type'=>'form','action'=>BIZUNO_AJAX."&bizRt=$this->moduleID/admin/uploadConvert",'enctype'=>"multipart/form-data"]]],
            'fields' => $fields,
            'jsReady'=> ['init'=>$jsReady]];
        $layout = array_replace_recursive($layout, $data);
    }

    /**
     * Method to receive a file to upload into the backup folder for db restoration
     * @param array $layout - structure coming in
     * @return modified $layout
     */
    public function uploadConvert(&$layout=[])
    {
        global $io;
        $io->uploadSave('fldBizFile', $this->dirBackup);
        // unzip file and put into folder in /temp folder
        $filename = clean($_FILES['fldBizFile']['name'], 'filename');
        $io->zipUnzip(BIZUNO_DATA."backups/$filename", BIZUNO_DATA);
        msgAdd("The file was uploaded and extracted to your data folder.");
    }
}