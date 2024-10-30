<?php
/*
 * @name Bizuno ERP - PhreeBooks Conversion Extension
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
 * @version    4.x Last Update: 2020-11-11
 * @filesource /bizuno-migrate/modules/xfrPhreeBooks/admin.php
 *
 * Release Notes:
 * The user security must be re-set manually as the Bizuno technique is now different than PhreeBooks
 * Departments are not used in Bizuno, warn for converters
 * Edit roles and assign users to roles
 * Reports may need editing, especially if they used a special class
 * POS table files, other addons???
 *
 * This script copies a working PhreeBooks database to the Bizuno database and converts to Bizuno format.
 * Both phreebooks and bizuno must be installed on the same server to operate this script
 * Step  1: Update and reformat configuration table constants
 * Step  2: Contacts Module
 * Step  3: Inventory Module
 * Step  4: Bizuno Module
 * Step  5: Department/Projects/Roles Module
 * Step  6: copy my_files
 * Step  7: PhreeBooks Module
 * Step  8: PhreeForm Module
 * Step  9: Prices Module
 * Step 10: Shipping Module
 * Step 11: my_files Files
 * Step 12: payment modules
 * Step 13: Copy folders
 * Step 14: RMA, Asset Modules
 * Step 15: Other clean up
 */

namespace bizuno;

ini_set('memory_limit','2048M');

class xfrPhreeBooksAdmin
{
    public  $moduleID = 'xfrPhreeBooks';
    private $dirBackup= 'backups/';
    private $total_steps = 22;

    function __construct()
    {
        if (session_status() == PHP_SESSION_NONE) { session_start(); }
        $this->pathSource= clean('src', 'text', 'get');
        $step            = clean('step','integer', 'get');
        $this->myFolder  = BIZUNO_DATA;
        $this->skipFolder= false;
        $lang = [];
        require_once(dirname(__FILE__)."/locale/en_US/language.php"); // replaces lang
        $this->lang      = $lang;
        $this->settings  = getModuleCache($this->moduleID, 'settings', false, false, []);
        $this->structure = [];
        if ($step) {
            $_SESSION['xfrCron']['step']   = $step;
            $_SESSION['xfrCron']['runaway']= 1;
        }
        if (!isset($_SESSION['xfrCron']['step']))   { $_SESSION['xfrCron']['step']   = 1; }
        if (!isset($_SESSION['xfrCron']['runaway'])){ $_SESSION['xfrCron']['runaway']= 1; }
        if (!@is_writable($this->myFolder)) { return msgAdd('Error: your business folder needs to be writable! I cannot reach it. Please submit a support ticket.'); }
        $_SESSION['xfrCron']['cache_lock'] = 'yes'; // lock the cache so it doesn't try to reload in the middle
    }

    /**
     * This extension adds a tab to convert from PhreeBooks to Bizuno, extends /bizuno/api/impExpMain
     */
    public function impExpMain(&$layout)
    {
        global $io;
        if (!$security = validateSecurity('bizuno', 'backup', 4)) { return; }
        $tabID     = clean('tabID','text', 'get');
        $upload_mb = min((int)(ini_get('upload_max_filesize')), (int)(ini_get('post_max_size')), (int)(ini_get('memory_limit')));
        $pathHTML  = "Path to PhreeBooks files directory (from home data folder as root, no leading or trailing slashes) ";
        $uploadHTML= "Upload a PhreeBooks database file to convert: ".sprintf(lang('max_upload'), $upload_mb);
        $strtHTML  = "<br />Select a PhreeBooks database file from the list below (Action -> Import icon) to start the import and conversion.";
        $fields    = [
//          'btnXfrReports'=> ['attr' =>['type'=>'button','value'=>'Just Reports'], 'events'=>['onClick'=>"hrefClick('$this->moduleID/admin/justReports');"]],
            'txtFile'=> ['order'=>20,'html'=>$pathHTML,'attr'=>['type'=>'raw']],
            'xfrPath'=> ['order'=>30,'break'=>true,'attr'=>['size'=>60, 'value'=>"backups/my_files"]],
            'txtUpld'=> ['order'=>40,'break'=>true,'html'=>$uploadHTML,'attr'=>['type'=>'raw']],
            'fldFile'=> ['order'=>50,'attr'=>['type'=>'file']],
            'btnFile'=> ['order'=>60,'break'=>true,'events'=>['onClick'=>"jqBiz('#frmConvert').submit();"],'attr'=>['type'=>'button','value'=>lang('upload')]],
            'txtStrt'=> ['order'=>70,'break'=>true,'html'=>$strtHTML,'attr'=>['type'=>'raw']]];
        $jsHead   = "restoreCancel = '';
function xfrClickFile(fn) {
    text = 'Starting import of database, be patient this can take a while!';
    jqBiz.messager.show({ title:'Message', msg:text, timeout:10000, width:400, height:200 });
    jqBiz('body').addClass('loading');
    jsonAction('$this->moduleID/admin/dbImport', 0, fn);
}
function xfrRequest() {
    jqBiz.ajax({ url:bizunoAjax+'&bizRt=$this->moduleID/admin/nextStep&src='+jqBiz('#xfrPath').val(), async:false, success:xfr_response });
}
function xfr_response(json) {
    if (json.message) displayMessage(json.message);
    jqBiz('#convertMsg').html(json.msg+' percent = '+json.percent);
    jqBiz('#xfrProgress').attr({ value:json.percent,max:100});
    if (json.percent >= 100) { jqBiz('#divConvertFinish').show(); } else window.setTimeout(\"xfrRequest('')\", 500);
}
function xferFileUpload() {
    jqBiz('#xfrUpload').fileupload({
        url: bizunoAjax+'&bizRt=bizuno/backup/uploadRestore',
        dataType: 'json',
        maxChunkSize: 500000,
        multipart: false,
        add: function (e, data) { data.context = jqBiz('#btnXfrUpload').show().click(function () { alert('starting'); jqBiz('#btnXfrUpload').hide(); jqBiz('#uplProgress').show(); data.submit(); }); },
        progressall: function (e, data) { alert('update loaded='+data.loaded+' and total='+data.total); var progress = parseInt(data.loaded / data.total * 100, 10); jqBiz('#uplProgress').attr({value:progress,max:100}); },
        done: function (e, data) { alert('done'); window.location = bizunoHome+'&bizRt=bizuno/api/impExpMain&tabID=xfrPB'; }
    });
}";
        $progHTML = '<div id="divViewConvert" style="text-align:center;display:none">
<table style="border:1px solid blue;width:500px;margin-top:50px;margin-left:auto;margin-right:auto;">
    <tbody>
        <tr><td>Converting, please wait...</td></tr>
        <tr><td id="convertMsg">&nbsp;</td></tr>
        <tr><td><progress id="xfrProgress"></progress></td></tr>
        <tr><td><div id="divConvertFinish" style="display:none">'.
            html5('btnXfrFinish', ['attr'=>['type'=>'button','value'=>lang('restart')],'events'=>['onClick'=>"jsonAction('bizuno/portal/logout');"]])."</div></td></tr>
    </tbody>
</table>
</div>";
        $data = [
            'tabs'=>['tabImpExp'=>['divs'=>['xfrPB'=>['order'=>50,'label'=>$this->lang['title'],'type'=>'divs','divs'=>[
                'heading' => ['order'=>15,'type'=>'html',    'html'=>"<h1>{$this->lang['title']}</h1>"],
                'progress'=> ['order'=>20,'type'=>'html',    'html'=>$progHTML],
                'dgRstr'  => ['order'=>80,'type'=>'datagrid','key' =>'dgConvert'],
                'formBOF' => ['order'=>60,'type'=>'form',    'key' =>'frmConvert'],
                'body'    => ['order'=>65,'type'=>'fields',  'keys'=>array_keys($fields)],
                'formEOF' => ['order'=>70,'type'=>'html',    'html'=>"</form>"]]]]]],
            'datagrid'=> ['dgConvert' => $this->dgConvert('dgConvert')],
            'forms'   => ['frmConvert'=> ['attr'=>['type'=>'form','action'=>BIZUNO_AJAX."&bizRt=$this->moduleID/admin/uploadConvert",'enctype'=>"multipart/form-data"]]],
            'fields'  => $fields,
            'jsHead'  => ['init'=>$jsHead],
            'jsReady' => ['init'=>"ajaxForm('frmConvert');"]];
        if ($tabID) { $data['tabs']['tabImpExp']['selected'] = $tabID; }
        $layout = array_replace_recursive($layout, $data);
    }

    /**
     * Import the database and prep some tables
     * @param array $layout -  structure coming in
     * @return modified $layout
     */
    public function dbImport(&$layout=[])
    {
        $dbFile = clean('data', 'text', 'get');
        if (!file_exists($this->myFolder.$dbFile)) { return msgAdd("Bad filename passed!"); }
        // save original configuration table or reloading page will fail
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."configuration TO ".BIZUNO_DB_PREFIX."config_bizuno");
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."phreeform TO "    .BIZUNO_DB_PREFIX."phreeform_bizuno");
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."users TO "        .BIZUNO_DB_PREFIX."users_bizuno");
        dbRestore($dbFile);
        if (!dbTableExists(BIZUNO_DB_PREFIX.'configuration')) { // import failed or wrong file, restore and exit script
            dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."config_bizuno TO "   .BIZUNO_DB_PREFIX."configuration");
            dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."phreeform_bizuno TO ".BIZUNO_DB_PREFIX."phreeform");
            dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."users_bizuno TO "    .BIZUNO_DB_PREFIX."users");
            $layout = array_replace_recursive($layout, ['content'=>['action'=>'eval',
                'actionData'=>"alert('Oh snap! Seems the database did not import correctly. I need to terminate the script!');"]]);
            return;
        }
        // take old configuration table and rename it
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."configuration TO "   .BIZUNO_DB_PREFIX."config_phreebooks");
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."phreeform TO "       .BIZUNO_DB_PREFIX."phreeform_phreebooks");
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."users TO "           .BIZUNO_DB_PREFIX."users_phreebooks");
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."config_bizuno TO "   .BIZUNO_DB_PREFIX."configuration");
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."phreeform_bizuno TO ".BIZUNO_DB_PREFIX."phreeform");
        dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."users_bizuno TO "    .BIZUNO_DB_PREFIX."users");
        // need to fix config_phreebooks table here as reload reads and breaks
        // some versions have configuration_id, if present remove it
        if (dbFieldExists(BIZUNO_DB_PREFIX."config_phreebooks", 'configuration_id')) {
            dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."config_phreebooks MODIFY configuration_id INT NOT NULL");
            dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."config_phreebooks DROP PRIMARY KEY");
            dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."config_phreebooks DROP configuration_id");
        }
        if (!dbFieldExists(BIZUNO_DB_PREFIX."config_phreebooks", 'config_key')) {
            dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."config_phreebooks
                CHANGE `configuration_key` `config_key` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'tag:ConfigKey;order:10',
                CHANGE `configuration_value` `config_value` TEXT COMMENT 'tag:ConfigValue;order:20'");
//          dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."config_phreebooks ADD PRIMARY KEY(`config_key`)"); // breaks some conversions, needs to be done manually
        }
        // make sure the config_value field is big enough (can be removed after upgrade is propagated through all hosts)
        dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."configuration CHANGE `config_value` `config_value` MEDIUMTEXT COMMENT 'type:hidden;tag:ConfigValue;order:20'");
        $layout = array_replace_recursive($layout, ['content'=>['action'=>'eval',
            'actionData'=>"alert('finished importing, ready to begin conversion.'); jqBiz('#divViewConvert').show(); xfrRequest();"]]);
    }

    /**
     *
     * @param type $layout
     */
    public function validateFolders(&$layout=[])
    {
        $error = false;
        if ($this->pathSource && is_dir($this->myFolder.$this->pathSource)) {
            msgAdd("Source Path Verified!",'success');
        } else {
            msgAdd("Source path cannot be verified, files cannot be moved!",'caution');
            $this->skipFolder = true; // this allows converting without any folder data
        }
        if (is_dir($this->myFolder) && is_writable($this->myFolder)) {
            msgAdd("Destination Path Exists and is Writable!",'success');
        } else {
            msgAdd("Destination Path Cannot be Verified!");
            $error = true;
        }
        $layout = array_replace_recursive($layout, ['content'=>['result'=>$error?'fail':'pass']]);
    }

    /**
     *
     */
    public function nextStep(&$layout=[])
    {
        // set execution time limit to a large number to allow extra time
        if (ini_get('max_execution_time') < 20000) { set_time_limit(20000); }
        $_SESSION['xfrCron']['runaway']++;
        msgDebug("\nStarting update step: ".$_SESSION['xfrCron']['step']);
        $this->upgrade($_SESSION['xfrCron']['step']);
        if ($_SESSION['xfrCron']['step'] >= $this->total_steps) { $_SESSION['xfrCron']['finished'] = true; }
        if ($_SESSION['xfrCron']['runaway'] > 30 || $_SESSION['xfrCron']['step'] >= $this->total_steps) {
            $_SESSION['xfrCron']['step'] = 0;
            $_SESSION['xfrCron']['runaway'] = 0;
            session_destroy();
            $data = ['content'=>['percent'=>'100','msg'=>"Completed step $this->total_steps of $this->total_steps"]];
        } else { // return to update progress bar and start next step
            $msg = "Completed step {$_SESSION['xfrCron']['step']} of $this->total_steps, ";
            $percent = round(100*$_SESSION['xfrCron']['step']/$this->total_steps, 0);
            $_SESSION['xfrCron']['step']++;
            $data = ['content'=>['percent'=>$percent,'msg'=>$msg]];
        }
        $this->logWrite();
        $layout = array_replace_recursive($layout, $data);
    }

    /**
     *
     */
    public function logWrite()
    {
        dbWriteCache();
        msgDebug("\nWriting log for this step.");
        msgDebugWrite('backups/upgrade_log.txt', true, true);
    }

    /**
     *
     * @param type $dirSrc
     * @param type $dirDest
     * @param type $prefix
     * @param type $suffix
     * @return type
     */
    private function moveDir($dirSrc, $dirDest, $prefix="", $suffix=".zip")
    {
        if (is_dir($this->myFolder."data/$dirSrc")) {
            if (!is_dir($this->myFolder."data/$dirDest")) { mkdir($this->myFolder."data/$dirDest", 0755, true); }
            $handle = @opendir($this->myFolder."data/$dirSrc");
            if (!$handle) { return; }
            while (false !== ($fileName = readdir($handle))) {
                if (in_array($fileName, ['.', '..'])) { continue; }
                $tmp = $prefix ? str_replace($prefix, "", $fileName) : $fileName;
                $rID = $suffix ? str_replace($suffix, "", $tmp) : $tmp;
                if (strpos($rID, "_") !== false) { $rID = substr($rID, 0, strpos($rID, "_")); }
                $aTime = fileatime($this->myFolder."data/$dirSrc/$fileName");
                $mTime = filemtime($this->myFolder."data/$dirSrc/$fileName");
                $newName = str_replace($prefix.$rID."_", '', $fileName);
                if (strlen($newName) < (2+strlen($suffix))) { $newName = $rID.'_'.$newName; }
                $newFile = $this->myFolder."data/$dirDest/rID_{$rID}_$newName";
                copy($this->myFolder."data/$dirSrc/$fileName", $newFile);
                touch($newFile, $mTime, $aTime);
                chmod($newFile, 0664);
            }
            closedir($handle);
//            @rmdir($this->myFolder."data/$dirSrc"); // for permission reasons folders will need to be removed manually
            if (strpos($dirSrc, '/')) { // remove parent folder as well
//                $parent = substr($dirSrc, 0, strpos($dirSrc, '/'));
//                @rmdir($this->myFolder."data/$parent");
            }
        }
    }

    /**
     *
     * @param type $src
     * @param type $dst
     * @return type
     */
    private function moveImages($src, $dst)
    {
        if (!$dir = @opendir($src)) { return; }
        @mkdir($dst, 0755, true);
        msgDebug("\nMoving images from path: $src to $dst");
        while (false !== ( $file = readdir($dir)) ) {
            if (($file != '.') && ( $file != '..' )) {
                if (is_dir("$src/$file")) {
                    $this->moveImages("$src/$file", "$dst/$file");
                } else {
                    copy("$src/$file", "$dst/$file");
                }
            }
        }
        closedir($dir);
    }

    /**
     *
     * @global type $db
     * @global type $io
     * @param type $step
     * @return type
     */
    function upgrade($step)
    {
        global $db, $io;
        $error  = false;
        switch ($step) {
            case 1: // copy my_files over ( to convert reports and save attachments )
                $pathToFiles= clean('src', 'path_rel', 'get'); // was (isset($_GET['src']) ? $_GET['src'] : '');
                $pathSource = $pathToFiles;
                if ($pathSource && is_dir($pathSource)) {
                    $dirSrc = $pathSource.'/';
                    $dirDest= "data/";
                    msgDebug("\n Copying from $dirSrc to $dirDest");
                    $io->folderCopy($dirSrc, $dirDest);
                } else {
                    msgDebug("\n Cannot find source files. looking for path = $pathToFiles");
                    msgAdd("Cannot find source files. looking for path = $pathToFiles. Skipping copy of files.", 'caution');
                }
                break;
            case 2: // Update and reformat config_phreebooks table and constants
                @unlink($this->myFolder.'backups/upgrade_log.txt');
                $result = dbGetMulti(BIZUNO_DB_PREFIX.'config_phreebooks');
                foreach ($result as $row) if (!defined($row['config_key'])) define($row['config_key'], $row['config_value']);
                // ********** change format of config_phreebooks table ***************
                // module contacts
                $keys = array('ADDRESS_BOOK_CONTACT_REQUIRED','ADDRESS_BOOK_ADDRESS1_REQUIRED','ADDRESS_BOOK_ADDRESS2_REQUIRED',
                    'ADDRESS_BOOK_CITY_TOWN_REQUIRED','ADDRESS_BOOK_STATE_PROVINCE_REQUIRED','ADDRESS_BOOK_POSTAL_CODE_REQUIRED',
                    'ADDRESS_BOOK_TELEPHONE1_REQUIRED','ADDRESS_BOOK_EMAIL_REQUIRED');
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key IN ('".implode("','",$keys)."')");

                // module inventory
                if (defined('INV_CHARGE_DEFAULT_SALES')) {
                  $settings  = array(
                    'general'=> array(
                        'weight_uom'     => 'LB',
                        'dim_uom'        => 'IN',
                        'tax_rate_id_c'  => INVENTORY_DEFAULT_TAX,
                        'tax_rate_id_v'  => INVENTORY_DEFAULT_PURCH_TAX,
                        'auto_add'       => INVENTORY_AUTO_ADD,
                        'auto_cost'      => ENABLE_AUTO_ITEM_COST,
                        'allow_neg_stock'=> '1',
                        'stock_usage'    => '1',
                        'barcode_length' => ORD_BAR_CODE_LENGTH,
                    ),
                    'phreebooks'=> array(
                        'sales_si'  => INV_STOCK_DEFAULT_SALES,
                        'inv_si'    => INV_STOCK_DEFAULT_INVENTORY,
                        'cogs_si'   => INV_STOCK_DEFAULT_COS,
                        'method_si' => INV_STOCK_DEFAULT_COSTING,
                        'sales_ms'  => INV_MASTER_STOCK_DEFAULT_SALES,
                        'inv_ms'    => INV_MASTER_STOCK_DEFAULT_INVENTORY,
                        'cogs_ms'   => INV_MASTER_STOCK_DEFAULT_COS,
                        'method_ms' => INV_MASTER_STOCK_DEFAULT_COSTING,
                        'sales_ma'  => INV_ASSY_DEFAULT_SALES,
                        'inv_ma'    => INV_ASSY_DEFAULT_INVENTORY,
                        'cogs_ma'   => INV_ASSY_DEFAULT_COS,
                        'method_ma' => INV_ASSY_DEFAULT_COSTING,
                        'sales_sr'  => INV_SERIALIZE_DEFAULT_SALES,
                        'inv_sr'    => INV_SERIALIZE_DEFAULT_INVENTORY,
                        'cogs_sr'   => INV_SERIALIZE_DEFAULT_COS,
                        'method_sr' => INV_SERIALIZE_DEFAULT_COSTING,
                        'sales_sa'  => INV_SERIALIZE_DEFAULT_SALES,
                        'inv_sa'    => INV_SERIALIZE_DEFAULT_INVENTORY,
                        'cogs_sa'   => INV_SERIALIZE_DEFAULT_COS,
                        'method_sa' => INV_SERIALIZE_DEFAULT_COSTING,
                        'sales_ns'  => INV_NON_STOCK_DEFAULT_SALES,
                        'inv_ns'    => INV_NON_STOCK_DEFAULT_INVENTORY,
                        'cogs_ns'   => INV_NON_STOCK_DEFAULT_COS,
                        'sales_sv'  => INV_SERVICE_DEFAULT_SALES,
                        'inv_sv'    => INV_SERVICE_DEFAULT_INVENTORY,
                        'cogs_sv'   => INV_SERVICE_DEFAULT_COS,
                        'sales_lb'  => INV_LABOR_DEFAULT_SALES,
                        'inv_lb'    => INV_LABOR_DEFAULT_INVENTORY,
                        'cogs_lb'   => INV_LABOR_DEFAULT_COS,
                        'sales_ai'  => INV_ACTIVITY_DEFAULT_SALES,
                        'sales_ci'  => INV_CHARGE_DEFAULT_SALES,
                    ),
                  );
                  $tmp = array_merge(getModuleCache('inventory', 'settings'), $settings);
                  setModuleCache('inventory', 'settings', false, $tmp);
                }
                $keys = array('INV_STOCK_DEFAULT_SALES','INV_STOCK_DEFAULT_INVENTORY','INV_STOCK_DEFAULT_COS','INV_STOCK_DEFAULT_COSTING',
                    'INV_MASTER_STOCK_DEFAULT_SALES','INV_MASTER_STOCK_DEFAULT_INVENTORY','INV_MASTER_STOCK_DEFAULT_COS','INV_MASTER_STOCK_DEFAULT_COSTING',
                    'INV_ASSY_DEFAULT_SALES','INV_ASSY_DEFAULT_INVENTORY','INV_ASSY_DEFAULT_COS','INV_ASSY_DEFAULT_COSTING','INV_SERIALIZE_DEFAULT_SALES',
                    'INV_SERIALIZE_DEFAULT_INVENTORY','INV_SERIALIZE_DEFAULT_COS','INV_SERIALIZE_DEFAULT_COSTING','INV_NON_STOCK_DEFAULT_SALES',
                    'INV_NON_STOCK_DEFAULT_INVENTORY','INV_NON_STOCK_DEFAULT_COS','INV_SERVICE_DEFAULT_SALES','INV_SERVICE_DEFAULT_INVENTORY',
                    'INV_SERVICE_DEFAULT_COS','INV_LABOR_DEFAULT_SALES','INV_LABOR_DEFAULT_INVENTORY','INV_LABOR_DEFAULT_COS',
                    'INV_ACTIVITY_DEFAULT_INVENTORY','INV_ACTIVITY_DEFAULT_SALES','INV_CHARGE_DEFAULT_INVENTORY','INV_CHARGE_DEFAULT_SALES',
                    'INV_DESC_DEFAULT_INVENTORY','INV_DESC_DEFAULT_SALES','INVENTORY_DEFAULT_TAX','INVENTORY_DEFAULT_PURCH_TAX','INVENTORY_AUTO_ADD',
                    'INVENTORY_AUTO_FILL','ORD_ENABLE_LINE_ITEM_BAR_CODE','ORD_BAR_CODE_LENGTH','ENABLE_AUTO_ITEM_COST');
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key IN ('".implode("','",$keys)."')");

                // module phreebooks
                if (defined('EMAIL_SMTPAUTH_MAIL_SERVER_PORT')) {
                    $settings  = array(
                        'general'=> array(
                            'round_tax_auth' => ROUND_TAX_BY_AUTH,
                        ),
                        'customers'=> array(
                            'gl_receivables' => AR_DEFAULT_GL_ACCT,
                            'gl_sales'       => AR_DEF_GL_SALES_ACCT,
                            'gl_cash'        => AR_SALES_RECEIPTS_ACCOUNT,
                            'gl_discount'    => AR_DISCOUNT_SALES_ACCOUNT,
                            'gl_deposit_cash'=> AR_DEF_DEPOSIT_ACCT,
                            'gl_liability'   => AR_DEF_DEP_LIAB_ACCT,
                            'gl_expense'     => '',
                            'terms'          => '0',
                            'auto_add'       => AUTO_INC_CUST_ID,
                            'show_status'    => AR_SHOW_CONTACT_STATUS,
                        ),
                        'vendors'=> array(
                            'gl_payables'    => AP_DEFAULT_PURCHASE_ACCOUNT,
                            'gl_purchases'   => AP_DEFAULT_INVENTORY_ACCOUNT,
                            'gl_cash'        => AP_PURCHASE_INVOICE_ACCOUNT,
                            'gl_discount'    => AP_DISCOUNT_PURCHASE_ACCOUNT,
                            'gl_deposit_cash'=> AP_DEF_DEPOSIT_ACCT,
                            'gl_liability'   => AP_DEF_DEP_LIAB_ACCT,
                            'gl_expense'     => '',
                            'terms'          => '2',
                            'auto_add'       => AUTO_INC_VEND_ID,
                            'show_status'    => AP_SHOW_CONTACT_STATUS,
                        ),
                    );
                    $tmp = array_merge(getModuleCache('phreebooks', 'settings'), $settings);
                    setModuleCache('phreebooks', 'settings', false, $tmp);
                }
                $keys = array('AUTO_UPDATE_PERIOD','SHOW_FULL_GL_NAMES','ROUND_TAX_BY_AUTH','ENABLE_BAR_CODE_READERS','SINGLE_LINE_ORDER_SCREEN',
                    'ENABLE_ORDER_DISCOUNT','ALLOW_NEGATIVE_INVENTORY','AR_DEFAULT_GL_ACCT','AR_DEF_GL_SALES_ACCT','AR_SALES_RECEIPTS_ACCOUNT',
                    'AR_DISCOUNT_SALES_ACCOUNT','AR_DEF_DEPOSIT_ACCT','AR_DEF_DEP_LIAB_ACCT','AR_USE_CREDIT_LIMIT','AR_CREDIT_LIMIT_AMOUNT',
                    'APPLY_CUSTOMER_CREDIT_LIMIT','AR_PREPAYMENT_DISCOUNT_PERCENT','AR_PREPAYMENT_DISCOUNT_DAYS','AR_NUM_DAYS_DUE','AR_ACCOUNT_AGING_START',
                    'AR_AGING_HEADING_1','AR_AGING_PERIOD_1','AR_AGING_HEADING_2','AR_AGING_PERIOD_2','AR_AGING_HEADING_3','AR_AGING_PERIOD_3',
                    'AR_AGING_HEADING_4','AR_CALCULATE_FINANCE_CHARGE','AR_ADD_SALES_TAX_TO_SHIPPING','AUTO_INC_CUST_ID','AR_SHOW_CONTACT_STATUS',
                    'AR_TAX_BEFORE_DISCOUNT','AP_DEFAULT_INVENTORY_ACCOUNT','AP_DEFAULT_PURCHASE_ACCOUNT','AP_PURCHASE_INVOICE_ACCOUNT','AP_DEF_FREIGHT_ACCT',
                    'AP_DISCOUNT_PURCHASE_ACCOUNT','AP_DEF_DEPOSIT_ACCT','AP_DEF_DEP_LIAB_ACCT','AP_USE_CREDIT_LIMIT','AP_CREDIT_LIMIT_AMOUNT',
                    'AP_PREPAYMENT_DISCOUNT_PERCENT','AP_PREPAYMENT_DISCOUNT_DAYS','AP_NUM_DAYS_DUE','AP_AGING_START_DATE','AP_AGING_HEADING_1',
                    'AP_AGING_DATE_1','AP_AGING_HEADING_2','AP_AGING_DATE_2','AP_AGING_HEADING_3','AP_AGING_DATE_3','AP_AGING_HEADING_4',
                    'AP_ADD_SALES_TAX_TO_SHIPPING','AUTO_INC_VEND_ID','AP_SHOW_CONTACT_STATUS','AP_TAX_BEFORE_DISCOUNT');
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key IN ('".implode("','",$keys)."')");

                // Module Bizuno
                if (defined('DATE_TIME_FORMAT')) {
                    $closest = null;
                    foreach(array(10,20,30,40,50) as $item) {
                        if ($closest == null || abs(MAX_DISPLAY_SEARCH_RESULTS - $closest) > abs($item - MAX_DISPLAY_SEARCH_RESULTS)) $closest = $item;
                    }
                    $settings = array(
                        'general'=> array(
                            'password_min' => ENTRY_PASSWORD_MIN_LENGTH,
                            'max_rows'     => $closest,
                            'session_max'  => SESSION_TIMEOUT_ADMIN,
                        ),
                        'company' => array(
                            'id'          => COMPANY_ID, // short name
                            'primary_name'=> COMPANY_NAME,
                            'contact'     => AR_CONTACT_NAME,
                            'contact_ap'  => AP_CONTACT_NAME,
                            'address1'    => COMPANY_ADDRESS1,
                            'address2'    => COMPANY_ADDRESS2,
                            'city'        => COMPANY_CITY_TOWN,
                            'state'       => COMPANY_ZONE,
                            'postal_code' => COMPANY_POSTAL_CODE,
                            'country'     => COMPANY_COUNTRY,
                            'telephone1'  => COMPANY_TELEPHONE1,
                            'telephone2'  => COMPANY_TELEPHONE2,
                            'telephone3'  => COMPANY_FAX,
                            'telephone4'  => '',
                            'email'       => COMPANY_EMAIL,
                            'website'     => COMPANY_WEBSITE,
                            'tax_id'      => TAX_ID,
                        ),
                        'bizuno_api' => array(
                            'gl_receivables'=> '',
                            'gl_sales'      => '',
                        ),
                        'locale'=> array(
                            'number_precision'=> '2',
                            'number_decimal'  => '.',
                            'number_thousand' => ',',
                            'number_prefix'   => '',
                            'number_suffix'   => '',
                            'number_neg_pfx'  => '-',
                            'number_neg_sfx'  => '',
                            'date_short'     => DATE_FORMAT,
                        ),
                    );
                    $tmp = array_merge(getModuleCache('bizuno', 'settings'), $settings);
                    setModuleCache('bizuno', 'settings', false, $tmp);
                }
                $keys = array('DEFAULT_LANGUAGE','COMPANY_ID','COMPANY_NAME','AR_CONTACT_NAME','AP_CONTACT_NAME','COMPANY_ADDRESS1',
                    'COMPANY_ADDRESS2','COMPANY_CITY_TOWN','COMPANY_ZONE','COMPANY_POSTAL_CODE','COMPANY_COUNTRY','COMPANY_TELEPHONE1',
                    'COMPANY_TELEPHONE2','COMPANY_FAX','COMPANY_EMAIL','COMPANY_WEBSITE','TAX_ID','ENABLE_MULTI_BRANCH','ENABLE_MULTI_CURRENCY',
                    'USE_DEFAULT_LANGUAGE_CURRENCY','ENABLE_ENCRYPTION','ENTRY_PASSWORD_MIN_LENGTH','MAX_DISPLAY_SEARCH_RESULTS',
                    'CFG_AUTO_UPDATE_CHECK','HIDE_SUCCESS_MESSAGES','AUTO_UPDATE_CURRENCY','LIMIT_HISTORY_RESULTS','SESSION_TIMEOUT_ADMIN',
                    'SESSION_AUTO_REFRESH','DEBUG','IE_RW_EXPORT_PREFERENCE','EMAIL_TRANSPORT','EMAIL_LINEFEED','EMAIL_USE_HTML',
                    'STORE_OWNER_EMAIL_ADDRESS','EMAIL_FROM','SERVER_ADDRESS','ADMIN_EXTRA_EMAIL_FORMAT','EMAIL_SMTPAUTH_MAILBOX',
                    'EMAIL_SMTPAUTH_PASSWORD','EMAIL_SMTPAUTH_MAIL_SERVER','EMAIL_SMTPAUTH_MAIL_SERVER_PORT','EMAIL_SMTPAUTH_TLS',
                    'CURRENCIES_TRANSLATIONS','DATE_FORMAT','DATE_DELIMITER','DATE_TIME_FORMAT');
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key IN ('".implode("','",$keys)."')");

                // module phreeform
/*                if (defined('PF_DEFAULT_TRIM_LENGTH')) {
                    $settings  = ['general' => ['default_font'=> 'helvetica',
                            'column_width'=> PF_DEFAULT_COLUMN_WIDTH,
                            'margin'      => PF_DEFAULT_MARGIN,
                            'title1'      => PF_DEFAULT_TITLE1,
                            'title2'      => PF_DEFAULT_TITLE2,
                            'paper_size'  => PF_DEFAULT_PAPERSIZE,
                            'orientation' => PF_DEFAULT_ORIENTATION,
                            'truncate_len'=> PF_DEFAULT_TRIM_LENGTH]];
                    $tmp = array_merge(getModuleCache('phreeform', 'settings'), $settings);
                    setModuleCache('phreeform', 'settings', false, $tmp);
                } */
                $keys = array('PF_DEFAULT_COLUMN_WIDTH','PF_DEFAULT_MARGIN','PF_DEFAULT_TITLE1','PF_DEFAULT_TITLE2','PF_DEFAULT_PAPERSIZE',
                    'PF_DEFAULT_ORIENTATION','PF_DEFAULT_TRIM_LENGTH','PF_DEFAULT_ROWSPACE','PDF_APP');
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key IN ('".implode("','",$keys)."')");
                break;
            case 3: // contacts module
                // earlier version do not have the attachments field
                if (!dbFieldExists(BIZUNO_DB_PREFIX."contacts", 'attachments')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."contacts ADD `attachments` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:Attachment;order:3' AFTER type");
                }
                // rename the attachments
                $result = dbGetMulti(BIZUNO_DB_PREFIX."contacts", "attachments<>''");
                msgDebug("\nworking with contacts attachments size = ".sizeof($result));
                if (is_array($result) && sizeof($result) > 0) foreach ($result as $row) {
                    if (empty($row['attachments'])) { continue; }
                    $fn = unserialize($row['attachments']);
                    foreach ($fn as $key => $name) {
                        $newName = "contacts_".$row['id']."_$name.zip";
                        @rename($this->myFolder."data/contacts/main/contacts_".$row['id']."_$key.zip", $this->myFolder."data/contacts/main/$newName");
                        @unlink($this->myFolder."data/contacts/main/contacts_".$row['id']."_$key.zip");
                    }
                }
                $this->moveDir("contacts/main", "contacts/uploads", "contacts_", $suffix=".zip");
                // Some users have added a field named terms
                if (dbFieldExists(BIZUNO_DB_PREFIX."contacts", 'terms')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."contacts CHANGE `terms` `old_terms` VARCHAR(32) NOT NULL DEFAULT '0'");
                }
                if (dbFieldExists(BIZUNO_DB_PREFIX."contacts", 'attachments')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."contacts
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `type` `type` CHAR(1) NOT NULL DEFAULT 'c' COMMENT 'type:hidden;tag:Type;order:5',
                    CHANGE `short_name` `short_name` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:ContactID;order:10',
                    CHANGE `inactive` `inactive` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:checkbox;tag:Status;order:15',
                    CHANGE `contact_first` `contact_first` VARCHAR(32) DEFAULT NULL COMMENT 'tag:FirstName;order:20',
                    CHANGE `contact_last` `contact_last` VARCHAR(32) DEFAULT NULL COMMENT 'tag:LastName;order:25',
                    CHANGE `contact_middle` `flex_field_1` VARCHAR(32) DEFAULT NULL COMMENT 'tag:Title;order:40',
                    CHANGE `store_id` `store_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:StoreID;order:30',
                    CHANGE `gl_type_account` `gl_account` VARCHAR(15) NOT NULL DEFAULT '' COMMENT 'tag:DefaultGLAccount;order:35',
                    CHANGE `gov_id_number` `gov_id_number` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'tag:GovID;order:45',
                    CHANGE `dept_rep_id` `rep_id` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'tag:RepID;order:55',
                    CHANGE `account_number` `account_number` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'tag:AccountNumber;order:50',
                    CHANGE `special_terms` `terms` VARCHAR(32) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:Terms;order:60',
                    CHANGE `price_sheet` `price_sheet` VARCHAR(32) DEFAULT NULL COMMENT 'type:select;tag:PriceSheetID;order:65',
                    CHANGE `tax_id` `tax_rate_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:TaxRateID;order:70',
                    CHANGE `first_date` `first_date` DATE DEFAULT NULL COMMENT 'tag:DateCreated;order:80',
                    CHANGE `last_update` `last_update` DATE DEFAULT NULL COMMENT 'tag:DateLastEntry;order:85',
                    CHANGE `last_date_1` `last_date_1` DATE DEFAULT NULL COMMENT 'tag:AltDate1;order:90',
                    CHANGE `last_date_2` `last_date_2` DATE DEFAULT NULL COMMENT 'tag:AltDate2;order:95',
                    CHANGE `attachments` `attach` ENUM('0','1') DEFAULT '0' COMMENT 'tag:Attachment;order:17'");
                // table address_book
                if (!dbFieldExists(BIZUNO_DB_PREFIX."address_book", 'country')) {
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."address_book SET `type`=SUBSTRING(`type`,2,1)");
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."address_book
                        CHANGE `address_id` `address_id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `ref_id` `ref_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:ReferenceID;order:5',
                        CHANGE `type` `type` CHAR(1) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:Type;order:10',
                        CHANGE `primary_name` `primary_name` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:PrimaryName;order:15',
                        CHANGE `contact` `contact` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Contact;order:20',
                        CHANGE `address1` `address1` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Address1;order:25',
                        CHANGE `address2` `address2` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Address2;order:30',
                        CHANGE `city_town` `city` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:City;order:35',
                        CHANGE `state_province` `state` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:State;order:40',
                        CHANGE `postal_code` `postal_code` VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'tag:PostalCode;order:45',
                        CHANGE `country_code` `country` CHAR(3) NOT NULL DEFAULT '' COMMENT 'tag:CountryISO3;order:50',
                        CHANGE `telephone1` `telephone1` VARCHAR(20) NULL DEFAULT '' COMMENT 'tag:Telephone1;order:55',
                        CHANGE `telephone2` `telephone2` VARCHAR(20) NULL DEFAULT '' COMMENT 'tag:Telephone2;order:60',
                        CHANGE `telephone3` `telephone3` VARCHAR(20) NULL DEFAULT '' COMMENT 'tag:Telephone3;order:65',
                        CHANGE `telephone4` `telephone4` VARCHAR(20) NULL DEFAULT '' COMMENT 'tag:Telephone4;order:70',
                        CHANGE `email` `email` VARCHAR(64) NULL DEFAULT '' COMMENT 'tag:Email;order:75',
                        CHANGE `website` `website` VARCHAR(48) NULL DEFAULT '' COMMENT 'tag:Website;order:80',
                        CHANGE `notes` `notes` TEXT COMMENT 'tag:Notes;order:85'");
                }
                // Table contacts_log
                // earlier version do not have the entered_by field
                if (!dbFieldExists(BIZUNO_DB_PREFIX."contacts_log", 'entered_by')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."contacts_log ADD `entered_by` INT(11) NOT NULL default '0' COMMENT 'tag:UserID;order:10' AFTER contact_id");
                }
                if (!dbFieldExists(BIZUNO_DB_PREFIX."contacts_log", 'id')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."contacts_log
                    CHANGE `log_id` `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `contact_id` `contact_id` INT(11) NOT NULL default '0' COMMENT 'type:hidden;tag:ContactRecordID;order:5',
                    CHANGE `entered_by` `entered_by` INT(11) NOT NULL default '0' COMMENT 'tag:UserID;order:10',
                    CHANGE `log_date` `LOG_DATE` DATETIME DEFAULT NULL COMMENT 'tag:DateEntered;order:15',
                    CHANGE `action` `action` VARCHAR(32) NOT NULL default '' COMMENT 'tag:Action;order:20',
                    CHANGE `notes` `notes` TEXT COMMENT 'tag:Notes;order:25'");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."departments");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."departments_types");
                break;
            case 4: // inventory module
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."journal_cogs_owed");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."journal_cogs_usage");
                dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."inventory_cogs_owed TO " .BIZUNO_DB_PREFIX."journal_cogs_owed");
                dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."inventory_cogs_usage TO ".BIZUNO_DB_PREFIX."journal_cogs_usage");
                // rename the attachments
                if (!dbFieldExists(BIZUNO_DB_PREFIX."inventory", 'attachments')) { // for older versions
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory ADD `attachments` ENUM('0','1') NOT NULL DEFAULT '0'");
                }
                $result = dbGetMulti(BIZUNO_DB_PREFIX."inventory", "attachments<>''");
                msgDebug("\nworking with inventory attachments size = ".sizeof($result));
                foreach ($result as $row) {
                    if (!$row['attachments']) { continue; }
                    $fn = @unserialize($row['attachments']);
                    if ($fn === false) { msgAdd("unserialize failure: ".$row['attachments']); }
                    else { if (is_array($fn)) { foreach ($fn as $key => $name) {
                        $newName = "inventory_".$row['id']."_$name.zip";
                        @rename($this->myFolder."data/inventory/attachments/inventory_".$row['id']."_$key.zip", $this->myFolder."data/inventory/attachments/$newName");
                        @unlink($this->myFolder."data/inventory/attachments/inventory_".$row['id']."_$key.zip");
                    } } }
                }
                // move the images
                $this->moveImages($this->myFolder."data/inventory/images", $this->myFolder."images");
                $this->moveDir("inventory/attachments", "inventory/uploads", "inventory_", $suffix=".zip");
                // earlier version do not have the price_sheet_v field
                if (!dbFieldExists(BIZUNO_DB_PREFIX."inventory", 'price_sheet_v')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory ADD `price_sheet_v` VARCHAR(32) DEFAULT NULL COMMENT 'type:select;tag:PriceSheetPurchase;order:35' AFTER price_sheet");
                }
                if (dbFieldExists(BIZUNO_DB_PREFIX."inventory", 'full_price_with_tax')) { dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory DROP `full_price_with_tax`"); }
                if (!dbFieldExists(BIZUNO_DB_PREFIX."inventory", 'gl_sales')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `sku` `sku` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:SKU;order:3',
                    CHANGE `inactive` `inactive` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:checkbox;tag:Inactive;order:5',
                    CHANGE `inventory_type` `inventory_type` CHAR(2) NOT NULL DEFAULT 'si' COMMENT 'type:select;tag:Type;order:7',
                    CHANGE `description_short` `description_short` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Description;order:9',
                    CHANGE `description_purchase` `description_purchase` VARCHAR(255) DEFAULT NULL COMMENT 'tag:DescriptionPurchase;order:11',
                    CHANGE `description_sales` `description_sales` VARCHAR(255) DEFAULT NULL COMMENT 'tag:DescriptionSales;order:15',
                    CHANGE `image_with_path` `image_with_path` VARCHAR(255) DEFAULT NULL COMMENT 'tag:Image;order:17',
                    CHANGE `account_sales_income` `gl_sales` VARCHAR(15) DEFAULT NULL COMMENT 'type:select;tag:GLAccountSales;order:19',
                    CHANGE `account_inventory_wage` `gl_inv` VARCHAR(15) DEFAULT NULL COMMENT 'type:select;tag:GLAccountInventory;order:21',
                    CHANGE `account_cost_of_sales` `gl_cogs` VARCHAR(15) DEFAULT NULL COMMENT 'type:select;tag:GLAccountCOGS;order:23',
                    CHANGE `item_taxable` `tax_rate_id_c` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:TaxRateIDCustomer;order:25',
                    CHANGE `purch_taxable` `tax_rate_id_v` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:TaxRateIDVendor;order:27',
                    CHANGE `item_cost` `item_cost` DOUBLE NOT NULL DEFAULT '0' COMMENT 'tag:Cost;order:29',
                    CHANGE `cost_method` `cost_method` ENUM('a','f','l') NOT NULL DEFAULT 'f' COMMENT 'type:select;tag:CostMethod;order:31',
                    CHANGE `price_sheet` `price_sheet_c` VARCHAR(32) DEFAULT NULL COMMENT 'type:select;tag:PriceSheetSales;order:33',
                    CHANGE `price_sheet_v` `price_sheet_v` VARCHAR(32) DEFAULT NULL COMMENT 'type:select;tag:PriceSheetPurchase;order:35',
                    CHANGE `full_price` `full_price` DOUBLE NOT NULL DEFAULT '0' COMMENT 'tag:Price;order:37',
                    CHANGE `item_weight` `item_weight` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:Weight;order:39',
                    CHANGE `quantity_on_hand` `qty_stock` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:QtyStock;order:40',
                    CHANGE `quantity_on_order` `qty_po` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:QtyPurchase;order:41',
                    CHANGE `quantity_on_sales_order` `qty_so` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:QtyOrder;order:43',
                    CHANGE `quantity_on_allocation` `qty_alloc` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:QtyAllocation;order:45',
                    CHANGE `minimum_stock_level` `qty_min` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:StockMinimum;order:47',
                    CHANGE `reorder_quantity` `qty_restock` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:StockReorder;order:49',
                    CHANGE `vendor_id` `vendor_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:StockVendor;order:51',
                    ADD `store_id` INT(11) NOT NULL DEFAULT '-1' COMMENT 'type:hidden;tag:StoreID;order:52' AFTER vendor_id,
                    CHANGE `lead_time` `lead_time` INT(3) NOT NULL DEFAULT '1' COMMENT 'tag:StockLeadTime;order:53',
                    CHANGE `upc_code` `upc_code` VARCHAR(13) NOT NULL DEFAULT '' COMMENT 'tag:UPCCode;order:55',
                    CHANGE `serialize` `serialize` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:checkbox;tag:Serialized;order:57',
                    CHANGE `creation_date` `creation_date` DATETIME DEFAULT NULL COMMENT 'tag:DateCreated;order:59',
                    CHANGE `last_update` `last_update` DATETIME DEFAULT NULL COMMENT 'tag:DateLastUpdate;order:61',
                    CHANGE `last_journal_date` `last_journal_date` DATETIME DEFAULT NULL COMMENT 'tag:DateLastJournal;order:63',
                    CHANGE `attachments` `attach` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'tag:Attachment;order:100'");
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory_assy_list
                    CHANGE `id` `id` INT(11) NOT NULL auto_increment COMMENT 'type:hidden;tag:RecordID;order:10',
                    CHANGE `ref_id` `ref_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:ReferenceID;order:20',
                    CHANGE `sku` `sku` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:SKU;order:30',
                    CHANGE `description` `description` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Description;order:40',
                    CHANGE `qty` `qty` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:Quantity;order:50'");
                if (!dbFieldExists(BIZUNO_DB_PREFIX."inventory_history", 'avg_cost')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory_history ADD `avg_cost` DOUBLE NOT NULL DEFAULT '0' AFTER unit_cost");
                }
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory_history
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `ref_id` `ref_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:ReferenceID;order:10',
                    CHANGE `journal_id` `journal_id` INT(2) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:JournalID;order:20',
                    CHANGE `store_id` `store_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:StoreID;order:30',
                    CHANGE `sku` `sku` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:SKU;order:40',
                    CHANGE `qty` `qty` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:Quantity;order:50',
                    CHANGE `serialize_number` `trans_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'tag:SerialNumber;order:60',
                    CHANGE `remaining` `remaining` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:Remaining;order:70',
                    CHANGE `unit_cost` `unit_cost` DOUBLE NOT NULL DEFAULT '0' COMMENT 'tag:UnitCost;order:80',
                    CHANGE `avg_cost` `avg_cost` DOUBLE NOT NULL DEFAULT '0' COMMENT 'tag:AveragCost;order:85',
                    CHANGE `post_date` `post_date` DATETIME DEFAULT NULL COMMENT 'tag:PostDate;order:90'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."inventory_history set avg_cost = unit_cost");
                if (sizeof(dbGetMulti(BIZUNO_DB_PREFIX.'inventory_ms_list')) > 0) {
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."inventory", 'invOptions')) {
                        dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory ADD invOptions text COMMENT 'type:hidden;label:Inventory Options'");
                    }
                }
                break;
            case 5: // bizuno module
                // earlier version do not have the ip_address field
                if (!dbFieldExists(BIZUNO_DB_PREFIX."audit_log", 'ip_address')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."audit_log ADD `ip_address` VARCHAR(15) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:IPAddress;order:30' AFTER user_id");
                }
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."audit_log SET action = CONCAT(action, '-', reference_id, '-', amount)");
                if (!dbFieldExists(BIZUNO_DB_PREFIX."audit_log", 'stats')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."audit_log ADD `stats` VARCHAR(32) DEFAULT '' AFTER action_date");
                if (!dbFieldExists(BIZUNO_DB_PREFIX."audit_log", 'log_entry')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."audit_log
                    CHANGE `id` `id` INT(15) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `user_id` `user_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:UserID;order:10',
                    ADD    `module_id` VARCHAR(24) DEFAULT '' COMMENT 'type:hidden;tag:ModuleID;order:20' AFTER user_id,
                    CHANGE `ip_address` `ip_address` VARCHAR(15) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:IPAddress;order:30',
                    CHANGE `action_date` `date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'type:hidden,tag:LogDate;order:40',
                    CHANGE `stats` `stats` VARCHAR(32) DEFAULT NULL COMMENT 'tag:Statistics;order:50',
                    CHANGE `action` `log_entry` VARCHAR(255) DEFAULT NULL COMMENT 'tag:LogEntry;order:60',
                    DROP   `reference_id`, DROP `amount`");
                if (!dbFieldExists(BIZUNO_DB_PREFIX."current_status", 'next_ref_j2')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."current_status
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `next_cust_id_num` `next_cust_id_num` VARCHAR(16) DEFAULT 'C10000' COMMENT 'tag:ContactC;order:2',
                    CHANGE `next_vend_id_num` `next_vend_id_num` VARCHAR(16) DEFAULT 'V10000' COMMENT 'tag:ContactV;order:3',
                    ADD    `next_ref_j2`                         VARCHAR(16) DEFAULT 'GL000001'  COMMENT 'tag:Journal2;order:4' AFTER next_vend_id_num,
                    CHANGE `next_ap_quote_num` `next_ref_j3`     VARCHAR(16) DEFAULT 'RFQ1000'   COMMENT 'tag:Journal3;order:5',
                    CHANGE `next_po_num`       `next_ref_j4`     VARCHAR(16) DEFAULT 'PO5000'    COMMENT 'tag:Journal4;order:6',
                    CHANGE `next_vcm_num`      `next_ref_j7`     VARCHAR(16) DEFAULT 'VCM1000'   COMMENT 'tag:Journal7;order:7',
                    CHANGE `next_ar_quote_num` `next_ref_j9`     VARCHAR(16) DEFAULT 'QU1000'    COMMENT 'tag:Journal9;order:8',
                    CHANGE `next_so_num`       `next_ref_j10`    VARCHAR(16) DEFAULT '000001'    COMMENT 'tag:Journal10;order:9',
                    CHANGE `next_inv_num`      `next_ref_j12`    VARCHAR(16) DEFAULT '200000',   COMMENT 'tag:Journal12;order:10',
                    CHANGE `next_cm_num`       `next_ref_j13`    VARCHAR(16) DEFAULT 'CM1000'    COMMENT 'tag:Journal13;order:11',
                    CHANGE `next_deposit_num`  `next_ref_j18`    VARCHAR(16) DEFAULT 'DP00001'   COMMENT 'tag:Journal18;order:12',
                    CHANGE `next_check_num`    `next_ref_j20`    VARCHAR(16) DEFAULT '100'       COMMENT 'tag:Journal20;order:13',
                    ADD    `next_tab_id` INT(11) NOT NULL DEFAULT '1' COMMENT 'type:hidden;tag:NextTabID;order:14'");
                // earlier version do not have the exp_date field
                if (!dbFieldExists(BIZUNO_DB_PREFIX."data_security", 'exp_date')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."data_security ADD `exp_date` DATE NOT NULL DEFAULT '2035-12-31' COMMENT 'type:hidden;tag:ExpirationDate;order:60' AFTER enc_value");
                }
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."data_security
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `module` `module` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:ModuleID;order:10',
                    CHANGE `ref_1` `ref_1` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:Reference1;order:20',
                    CHANGE `hint` `hint` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'tag:Hint;order:40',
                    CHANGE `enc_value` `enc_value` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:EncryptedValue;order:50',
                    CHANGE `exp_date` `exp_date` DATE NOT NULL DEFAULT '2035-12-31' COMMENT 'type:hidden;tag:ExpirationDate;order:60',
                    DROP `ref_2`");
                // convert is_role from table users to table roles
                // earlier version do not have the is_role field
                if (!dbFieldExists(BIZUNO_DB_PREFIX."users_phreebooks", 'is_role')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."users_phreebooks ADD `is_role` DATE NOT NULL DEFAULT '0'");
                }
                if (dbFieldExists(BIZUNO_DB_PREFIX."users_phreebooks", 'is_role')) {
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."users_phreebooks", "is_role='1' AND inactive='0'");
                    if (sizeof($result) > 0) {
                        foreach ($result as $row) {
                            $exists = dbGetValue(BIZUNO_DB_PREFIX."roles", 'id', "title='{$row['admin_name']}'");
                            if (!$exists) { $id = dbWrite(BIZUNO_DB_PREFIX."roles", ['title'=>$row['admin_name']]); }
                        }
                    }
                    dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."users_phreebooks WHERE is_role='1'");
                }
                if (!dbFieldExists(BIZUNO_DB_PREFIX."users_phreebooks", 'email')) {
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."users_phreebooks", "is_role='0' AND inactive='0'");
                    foreach ($result as $row) {
                        $exists = dbGetValue(BIZUNO_DB_PREFIX."users", 'admin_id', "email='{$row['admin_email']}'");
                        if (!$exists) { dbWrite(BIZUNO_DB_PREFIX."users", ['email'=>$row['admin_email'],'title'=>$row['admin_name'],'settings'=>'[]']); }
                    }
                }
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users SET role_id = 1"); // set all role to the first one for now, this will need to be manually updated by admin
                if (!dbFieldExists(BIZUNO_DB_PREFIX."users_profiles", 'settings')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."users_profiles
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `user_id` `user_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:UserID;order:10',
                    CHANGE `menu_id` `menu_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:MenuID;order:20',
                    CHANGE `module_id` `module_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:ModuleID;order:30',
                    CHANGE `dashboard_id` `dashboard_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:DashboardID;order:40',
                    CHANGE `column_id` `column_id` INT(3) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:ColumnID;order:50',
                    CHANGE `row_id` `row_id` INT(3) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:RowID;order:60',
                    CHANGE `params` `settings` TEXT COMMENT 'type:hidden;tag:Settings;order:70'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET column_id=column_id - 1");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='home' WHERE menu_id='index'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='customers' WHERE menu_id='cat_ar'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='vendors' WHERE menu_id='cat_ap'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='inventory' WHERE menu_id='cat_inv'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='gl' WHERE menu_id='cat_gl'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='banking' WHERE menu_id='cat_bnk'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='employees' WHERE menu_id='cat_em'"); // employees
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='tools' WHERE menu_id='cat_tools'"); // tools
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='quality' WHERE menu_id='cat_qa'"); // quality
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET menu_id='company' WHERE menu_id='cat_co'"); // company
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET module_id='bizuno' WHERE module_id='phreedom'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET dashboard_id='my_links' WHERE dashboard_id='personal_links'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."users_profiles SET dashboard_id='my_to_do' WHERE dashboard_id='to_do'");
                break;
            case 6: // some db changes
                // convert extra tabs and fields to db comment field
                if (dbTableExists(BIZUNO_DB_PREFIX."xtra_fields")) {
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."xtra_fields", "tab_id > 0", "module_id");
                    $props = array();
                    foreach ($result as $row) {
                        $props[$row['module_id']][$row['field_name']] = ['tab'=>$row['tab_id'], 'label'=>$row['description'],
                            'group'=>!empty($row['group_by'])  ? $row['group_by']  : '',
                            'order'=>!empty($row['sort_order'])? $row['sort_order']: ''];
                    }
                    foreach ($props as $table => $fields) {
                        $tableDetails = dbLoadStructure(BIZUNO_DB_PREFIX.$table);
                        $order = 200;
                        foreach ($fields as $field => $settings) {
                            $values = $tableDetails[$field];
                            $values['tab']  = $settings['tab'];
                            $values['order']= $settings['order'] ? $settings['order'] : $order;
                            $values['group']= $settings['group'];
                            $values['label']= $settings['label'];
                            $db->alterField(BIZUNO_DB_PREFIX.$table, $field, $values);
                            if (!$settings['order']) $order = $order + 10;
                        }
                    }
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."xtra_fields");
                }
                if (dbGetRow(BIZUNO_DB_PREFIX.'projects_costs')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."projects_costs
                        CHANGE `cost_id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `description_short` `title` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'tag:Title;order:10',
                        CHANGE `description_long` `description` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:Description;order:20',
                        CHANGE `cost_type` `cost_type` VARCHAR(3) NOT NULL DEFAULT '' COMMENT 'tag:CostType;order:30',
                        CHANGE `inactive` `inactive` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'tag:Inactive;order:40'");
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."projects_phases
                        CHANGE `phase_id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `description_short` `title` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'tag:Title;order:10',
                        CHANGE `description_long` `description` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:Description;order:20',
                        CHANGE `cost_type` `cost_type` VARCHAR(3) NOT NULL DEFAULT '' COMMENT 'tag:CostType;order:30',
                        CHANGE `cost_breakdown` `cost_breakdown` VARCHAR(3) NOT NULL DEFAULT '' COMMENT 'tag:CostBreakdown;order:40',
                        CHANGE `inactive` `inactive` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'tag:Inactive;order:50'");
                } else {
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."projects_costs");
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."projects_phases");
                }
                break;
            case 7: // phreebooks module
                $this->moveDir("phreebooks/orders", "phreebooks/uploads", "order_", $suffix=".zip");
                $def_cur = dbGetValue(BIZUNO_DB_PREFIX."config_phreebooks", 'config_value', "config_key='DEFAULT_CURRENCY'");
                setModuleCache('phreebooks', 'currency', 'defISO', $def_cur);
                $period  = dbGetValue(BIZUNO_DB_PREFIX."config_phreebooks", 'config_value', "config_key='CURRENT_ACCOUNTING_PERIOD'");
                // drop and rename some tables
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."chart_of_accounts_types");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."accounts_history");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."journal_periods");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."journal_gl_accounts");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."journal_history");
                dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."accounting_periods TO "       .BIZUNO_DB_PREFIX."journal_periods");
                dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."chart_of_accounts_history TO ".BIZUNO_DB_PREFIX."journal_history");
                // convert table chart_of_accounts to file and set defaults, used as registry settings
                $result = dbGetMulti(BIZUNO_DB_PREFIX."chart_of_accounts");
                $output = array();
                foreach ($result as $row) {
                    $tmp = array('id'=>$row['id'], 'type'=>$row['account_type'], 'cur'=>$def_cur, 'title'=>$row['description'], 'inactive'=>$row['account_inactive']);
                    if ($row['heading_only']) $tmp['heading'] = 1;
                    if ($row['primary_acct_id']) $tmp['primary'] = $row['primary_acct_id'];
                    $output['accounts'][$row['id']] = $tmp;
                }
                // set the defaults
                $defRE = dbGetValue(BIZUNO_DB_PREFIX."chart_of_accounts", 'id', "account_type=44");
                $output['defaults'][$def_cur] = array(
                    0  => getModuleCache('phreebooks', 'settings', 'customers', 'gl_cash'), // Cash
                    2  => getModuleCache('phreebooks', 'settings', 'customers', 'gl_receivables'), // Accounts Receivable
                    4  => getModuleCache('inventory',  'settings', 'phreebooks','inv_si'), // Inventory
//                    6  => getModuleCache('phreebooks', 'settings', 'customers', 'TBD'), // Other current assets
//                    8  => getModuleCache('phreebooks', 'settings', 'customers', 'TBD'), // fixed assets
//                    10 => getModuleCache('phreebooks', 'settings', 'customers', 'TBD'), // Accumulated Depreciation
//                    12 => getModuleCache('phreebooks', 'settings', 'customers', 'TBD'), // Other Assets
                    20 => getModuleCache('phreebooks', 'settings', 'vendors',   'gl_payables'), // Accounts Payable
//                    22 => getModuleCache('phreebooks', 'settings', 'customers', 'TBD'), // Other Current Liabilities
//                    24 => getModuleCache('phreebooks', 'settings', 'customers', 'TBD'), // Long Term Liabilities
                    30 => getModuleCache('phreebooks', 'settings', 'customers', 'gl_sales'), // Income
                    32 => getModuleCache('inventory',  'settings', 'phreebooks','cogs_si'), // Cost of Sales
                    34 => getModuleCache('inventory',  'settings', 'phreebooks','inv_ns'), // Expenses
//                    40 => getModuleCache('phreebooks', 'settings', 'customers', 'TBD'), // Equity - Does Not Close
//                    42 => getModuleCache('phreebooks', 'settings', 'customers', 'TBD'), // Equity - Gets Closed
                    44 => $defRE, // Equity - Retained Earnings
                );
                setModuleCache('phreebooks', 'chart', false, $output);
                // change the field settings
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_periods
                    CHANGE `period` `period` INT(11) DEFAULT '0' COMMENT 'tag:Period;order:1',
                    CHANGE `fiscal_year` `fiscal_year` INT(11) DEFAULT '0' COMMENT 'tag:FiscalYear;order:10',
                    CHANGE `start_date` `start_date` DATE DEFAULT NULL COMMENT 'tag:StartDate;order:20',
                    CHANGE `end_date` `end_date` DATE DEFAULT NULL COMMENT 'tag:EndDate;order:30',
                    CHANGE `date_added` `date_added` DATE DEFAULT NULL COMMENT 'tag:DateAdded;order:40',
                    CHANGE `last_update` `last_update` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'tag:LastUpdate;order:50'");
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_history
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `period` `period` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:Period;order:10',
                    CHANGE `account_id` `gl_account` CHAR(15) NOT NULL DEFAULT '' COMMENT 'tag:GLAccount;order:20',
                    ADD    `gl_type` INT(2) NOT NULL DEFAULT '0' COMMENT 'tag:GLAccountType;order:30' AFTER `gl_account`,
                    CHANGE `beginning_balance` `beginning_balance` DOUBLE NOT NULL DEFAULT '0' COMMENT 'tag:BeginningBalance;order:40',
                    CHANGE `debit_amount` `debit_amount` DOUBLE NOT NULL DEFAULT '0' COMMENT 'format:currency;tag:DebitAmount;order:50',
                    CHANGE `credit_amount` `credit_amount` DOUBLE NOT NULL DEFAULT '0' COMMENT 'format:currency;tag:CreditAmount;order:60',
                    CHANGE `budget` `budget` DOUBLE NOT NULL DEFAULT '0' COMMENT 'format:currency;tag:BudgetAmount;order:70',
                    ADD    `stmt_balance` DOUBLE NOT NULL DEFAULT '0' COMMENT 'format:currency;tag:StatementBalance;order:80' AFTER `budget`,
                    CHANGE `last_update` `last_update` DATE DEFAULT NULL COMMENT 'tag:LastUpdate;order:90'");
                // set the current fiscal calendar
                bizAutoLoad(BIZUNO_LIB.'controllers/phreebooks/functions.php', 'phreebooksProcess', 'function');
                $props = dbGetPeriodInfo($period);
                setModuleCache('phreebooks', 'fy', false, $props);
                // move statement balance to journal_history and remove table reconciliation
                $result = dbGetMulti(BIZUNO_DB_PREFIX.'reconciliation', '', '', array('period', 'gl_account', 'statement_balance'));
                if ($result) foreach ($result as $row) {
                    dbWrite(BIZUNO_DB_PREFIX.'journal_history', array('stmt_balance'=>$row['statement_balance']), 'update', "period='{$row['period']}' AND gl_account='{$row['gl_account']}'");
                }
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX.'reconciliation');
                $glAccts = dbGetMulti(BIZUNO_DB_PREFIX."chart_of_accounts");
                foreach ($glAccts as $row) dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."journal_history SET gl_type={$row['account_type']} WHERE gl_account='{$row['id']}'");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."chart_of_accounts");
                // earlier version do not have the item_cnt field
                if (!dbFieldExists(BIZUNO_DB_PREFIX."journal_item", 'item_cnt')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_item ADD `item_cnt` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:LineNumber;order:10' AFTER ref_id");
                }
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."journal_item SET project_id=CONCAT(serialize_number, project_id)");
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_item
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `ref_id` `ref_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:ReferenceID;order:5',
                    CHANGE `item_cnt` `item_cnt` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:LineNumber;order:10',
                    CHANGE `so_po_item_ref_id` `item_ref_id` INT(11) DEFAULT NULL COMMENT 'type:hidden;tag:SoPoItemRefID;order:15',
                    CHANGE `gl_type` `gl_type` CHAR(3) NOT NULL DEFAULT '' COMMENT 'type:hidden;tag:GLType;order:20',
                    CHANGE `reconciled` `reconciled` INT(4) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:Reconciled;order:25',
                    CHANGE `sku` `sku` VARCHAR(24) DEFAULT NULL COMMENT 'tag:SKU;order:30',
                    CHANGE `qty` `qty` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:Quantity;order:35',
                    CHANGE `description` `description` VARCHAR(255) DEFAULT NULL COMMENT 'tag:Description;order:40',
                    CHANGE `debit_amount` `debit_amount` DOUBLE DEFAULT '0' COMMENT 'format:currency;tag:DebitAmount;order:45',
                    CHANGE `credit_amount` `credit_amount` DOUBLE DEFAULT '0' COMMENT 'format:currency;tag:CreditAmount;order:50',
                    CHANGE `gl_account` `gl_account` VARCHAR(15) NOT NULL DEFAULT '' COMMENT 'type:select;tag:GLAccount;order:55',
                    CHANGE `taxable` `tax_rate_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:TaxRateID;order:60',
                    CHANGE `full_price` `full_price` DOUBLE NOT NULL DEFAULT '0' COMMENT 'format:currency;tag:PriceFull;order:65',
                    CHANGE `serialize` `serialize` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:Serialize;order:70',
                    CHANGE `project_id` `trans_code` VARCHAR(64) DEFAULT NULL COMMENT 'tag:TransactionID;order:75',
                    CHANGE `post_date` `post_date` DATE DEFAULT NULL COMMENT 'format:date;tag:PostDate;order:80',
                    CHANGE `date_1` `date_1` DATETIME DEFAULT NULL COMMENT 'format:date;tag:ItemDate1;order:85',
                    DROP `serialize_number`");
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_main
                    CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `period` `period` INT(2) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:Period;order:5',
                    CHANGE `journal_id` `journal_id` INT(2) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:JournalID;order:10',
                    CHANGE `post_date` `post_date` DATE DEFAULT NULL COMMENT 'format:date;tag:PostDate;order:15',
                    CHANGE `terminal_date` `terminal_date` DATE DEFAULT NULL COMMENT 'format:date;tag:TerminalDate;order:20',
                    CHANGE `store_id` `store_id` INT(11) DEFAULT '0' COMMENT 'type:hidden;tag:StoreID;order:25',
                    CHANGE `description` `description` VARCHAR(64) DEFAULT NULL COMMENT 'tag:Description;order:30',
                    CHANGE `closed` `closed` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:checkbox;tag:Closed;order:35',
                    CHANGE `closed_date` `closed_date` DATE DEFAULT NULL COMMENT 'type:hidden;tag:ClosedDate;order:40',
                    CHANGE `printed` `printed` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:Printed;order:45',
                    CHANGE `waiting` `waiting` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:checkbox;tag:Waiting;order:50',
                    ADD    `attach` ENUM('0','1') DEFAULT '0' COMMENT 'tag:Attachment;order:52' AFTER `waiting`,
                    CHANGE `discount` `discount` DOUBLE NOT NULL DEFAULT '0' COMMENT 'format:currency;tag:TotalDiscount;order:55',
                    CHANGE `sales_tax` `sales_tax` DOUBLE NOT NULL DEFAULT '0' COMMENT 'format:currency;tag:TotalTax;order:60',
                    CHANGE `total_amount` `total_amount` DOUBLE NOT NULL DEFAULT '0' COMMENT 'format:currency;tag:TotalAmount;order:65',
                    CHANGE `tax_auths` `tax_rate_id` INT(11) NOT NULL default '0' COMMENT 'tag:TaxRateID;order:70',
                    CHANGE `terms` `terms` VARCHAR(32) DEFAULT '0' COMMENT 'type:hidden;tag:Terms;order:75',
                    CHANGE `currencies_code` `currency` CHAR(3) NOT NULL DEFAULT '$def_cur' COMMENT 'type:hidden;tag:CurrencyISO;order:80',
                    CHANGE `currencies_value` `currency_rate` DOUBLE NOT NULL DEFAULT '1.0' COMMENT 'type:hidden;tag:CurrencyExchangeRate;order:85',
                    CHANGE `so_po_ref_id` `so_po_ref_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:SoPoRefID;order:90',
                    CHANGE `purchase_invoice_id` `invoice_num` VARCHAR(24) DEFAULT NULL COMMENT 'tag:ReferenceNumber;order:95',
                    CHANGE `purch_order_id` `purch_order_id` VARCHAR(24) DEFAULT NULL COMMENT 'tag:PurchaseOrderID;order:100',
                    CHANGE `recur_id` `recur_id` INT(11) DEFAULT NULL COMMENT 'type:hidden;tag:RecurID;order:105',
                    CHANGE `admin_id` `admin_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:UserID;order:110',
                    CHANGE `rep_id` `rep_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:ContactID;order:115',
                    CHANGE `gl_acct_id` `gl_acct_id` VARCHAR(15) DEFAULT NULL COMMENT 'type:select;tag:GLAccount;order:120',
                    CHANGE `bill_acct_id` `contact_id_b` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:BillingContactID;order:125',
                    CHANGE `bill_address_id` `address_id_b` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:BillingAddressID;order:130',
                    CHANGE `bill_primary_name` `primary_name_b` VARCHAR(32) DEFAULT NULL COMMENT 'tag:BillingPrimaryName;order:135',
                    CHANGE `bill_contact` `contact_b` VARCHAR(32) DEFAULT NULL COMMENT 'tag:BillingAttention;order:140',
                    CHANGE `bill_address1` `address1_b` VARCHAR(32) DEFAULT NULL COMMENT 'tag:BillingAddress1;order:145',
                    CHANGE `bill_address2` `address2_b` VARCHAR(32) DEFAULT NULL COMMENT 'tag:BillingAddress2;order:150',
                    CHANGE `bill_city_town` `city_b` VARCHAR(24) DEFAULT NULL COMMENT 'tag:BillingCity;order:155',
                    CHANGE `bill_state_province` `state_b` VARCHAR(24) DEFAULT NULL COMMENT 'tag:BillingState;order:160',
                    CHANGE `bill_postal_code` `postal_code_b` VARCHAR(10) DEFAULT NULL COMMENT 'tag:BillingPostalCode;order:165',
                    CHANGE `bill_country_code` `country_b` CHAR(3) DEFAULT NULL COMMENT 'type:select;tag:BillingCountryISO3;order:170',
                    CHANGE `bill_telephone1` `telephone1_b` VARCHAR(20) DEFAULT NULL COMMENT 'tag:BillingTelephone1;order:175',
                    CHANGE `bill_email` `email_b` VARCHAR(48) DEFAULT NULL COMMENT 'tag:BillingEmail;order:180',
                    CHANGE `freight` `freight` DOUBLE DEFAULT '0' COMMENT 'format:currency;tag:TotalShipping;order:200',
                    CHANGE `shipper_code` `method_code` VARCHAR(16) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:MethodID;order:205',
                    CHANGE `drop_ship` `drop_ship` ENUM('0','1') DEFAULT '0' COMMENT 'type:checkbox;tag:DropShip;order:210',
                    CHANGE `ship_acct_id` `contact_id_s` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:ShippingContactID;order:215',
                    CHANGE `ship_address_id` `address_id_s` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:ShippingAddressID;order:220',
                    CHANGE `ship_primary_name` `primary_name_s` VARCHAR(32) DEFAULT NULL COMMENT 'tag:ShippingPrimaryName;order:225',
                    CHANGE `ship_contact` `contact_s` VARCHAR(32) DEFAULT NULL COMMENT 'tag:ShippingAttention;order:230',
                    CHANGE `ship_address1` `address1_s` VARCHAR(32) DEFAULT NULL COMMENT 'tag:ShippingAddress1;order:235',
                    CHANGE `ship_address2` `address2_s` VARCHAR(32) DEFAULT NULL COMMENT 'tag:ShippingAddress2;order:240',
                    CHANGE `ship_city_town` `city_s` VARCHAR(24) DEFAULT NULL COMMENT 'tag:ShippingCity;order:245',
                    CHANGE `ship_state_province` `state_s` VARCHAR(24) DEFAULT NULL COMMENT 'tag:ShippingState;order:250',
                    CHANGE `ship_postal_code` `postal_code_s` VARCHAR(10) DEFAULT NULL COMMENT 'tag:ShippingPostalCode;order:255',
                    CHANGE `ship_country_code` `country_s` CHAR(3) DEFAULT NULL COMMENT 'type:select;tag:ShippingCountryISO3;order:260',
                    CHANGE `ship_telephone1` `telephone1_s` VARCHAR(20) DEFAULT NULL COMMENT 'tag:ShippingTelephone1;order:265',
                    CHANGE `ship_email` `email_s` VARCHAR(48) DEFAULT NULL COMMENT 'tag:ShippingEmail;order:270'");
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_main
                    DROP INDEX `bill_acct_id`,
                    ADD INDEX `contact_id_b` (`contact_id_b`),
                    ADD INDEX `invoice_num` (`invoice_num`),
                    ADD INDEX `so_po_ref_id` (`so_po_ref_id`)");
                if (!dbFieldExists(BIZUNO_DB_PREFIX.'journal_main', 'notes')) { // Add notes field to the journal_main table, some usaers have already done this
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_main ADD `notes` VARCHAR(255) DEFAULT NULL COMMENT 'tag:Notes;order:90' AFTER terms");
                }
                if (dbTableExists(BIZUNO_DB_PREFIX."tax_authorities")) {
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."tax_authorities");
                    $tax_auths = array();
                    foreach ($result as $row) {
                        $tax_auths[$row['tax_auth_id']] = array('cID'=>$row['vendor_id'], 'text'=>$row['description_short'], 'rate'=>$row['tax_rate'], 'glAcct'=>$row['account_id']);
                    }
                    $rates  = array();
                    $totals = array();
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."tax_rates");
                    foreach ($result as $row) { // build the settings fields
                        $totals[$row['tax_rate_id']] = 0;
                        $arrAuths = explode(':', $row['rate_accounts']);
                        foreach ($arrAuths as $aID) {
                            if (!$aID) { continue; }
                            $totals[$row['tax_rate_id']] += $tax_auths[$aID]['rate'];
                            $rates[$row['tax_rate_id']][] = $tax_auths[$aID];
                        }
                    }
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."tax_authorities");
                }
                if (!dbFieldExists(BIZUNO_DB_PREFIX."tax_rates", 'id')) { dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."tax_rates
                    CHANGE `tax_rate_id` `id` INT(3) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `type` `type` VARCHAR(1) NOT NULL DEFAULT 'c' COMMENT 'type:hidden;tag:Type;order:10',
                    ADD    `inactive` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:checkbox;tag:Inactive;order:20' AFTER `type`,
                    CHANGE `description_short` `title` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Title;order:30',
                    ADD    `tax_rate` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:TaxRate;order:40' AFTER `title`,
                    ADD    `start_date` DATE DEFAULT '2000-01-01' COMMENT 'tag:StartDate;format:date;order:50' AFTER `tax_rate`,
                    ADD    `end_date` DATE DEFAULT '2029-12-31' COMMENT 'tag:EndDate;format:date;order:60' AFTER `start_date`,
                    CHANGE `rate_accounts` `settings` TEXT COMMENT 'tag:Settings;order:70;format:stringify',
                    DROP   `description_long`, DROP `freight_taxable`"); }
                // fill in the tax rate settings
                if (isset($rates)) { foreach ($rates as $id => $settings) {
                    dbWrite(BIZUNO_DB_PREFIX."tax_rates", ['settings'=>json_encode($settings), 'tax_rate'=>$totals[$id]], 'update', "id='$id'");
                } }
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."journal_item SET gl_type='itm' WHERE gl_type IN ('sos','soo','poo','por')");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."journal_item SET gl_type='pmt' WHERE gl_type = 'chk'");
                // PhreeSoft books did not have this index set!, failed sql and stopped script
                //If the query returns zero (0) then the index does not exists, then you can create it.
                //If the query returns a positive number, then the index exists, then you can drop it.
                $stmt = dbGetResult("SHOW INDEX FROM ".BIZUNO_DB_PREFIX."journal_item WHERE KEY_NAME='so_po_item_ref_id'");
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($result) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_item DROP INDEX so_po_item_ref_id, ADD INDEX item_ref_id (item_ref_id)");
                } else {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_item ADD INDEX item_ref_id (item_ref_id)");
                }
                break;
            case 8: // phreeform module - Skip all but images as this makes more of a mess, just keep Bizuno current forms
/*                if (!dbFieldExists(BIZUNO_DB_PREFIX."phreeform", 'doc_data')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."phreeform
                    CHANGE `id` `id` INT(10) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `parent_id` `parent_id` INT(10) DEFAULT '0' COMMENT 'type:hidden;tag:ParentID;order:10',
                    CHANGE `doc_group` `group_id` VARCHAR(10) DEFAULT '' COMMENT 'type:hidden;tag:GroupID;order:20',
                    CHANGE `doc_ext` `mime_type` VARCHAR(4) DEFAULT NULL COMMENT 'type:hidden;tag:MimeType;order:30',
                    CHANGE `doc_title` `title` VARCHAR(64) DEFAULT '' COMMENT 'tag:Title;order:40',
                    CHANGE `create_date` `create_date` DATE DEFAULT NULL COMMENT 'tag:CreateDate;order:50',
                    CHANGE `last_update` `last_update` DATE DEFAULT NULL COMMENT 'tag:LastUpdate;order:60',
                    CHANGE `security` `security` VARCHAR(255) DEFAULT 'u:0;g:0' COMMENT 'type:hidden;tag:Security;order:70',
                    ADD    `doc_data` TEXT DEFAULT NULL COMMENT 'tag:DocData;order:80',
                    DROP   `doc_type`");
                }
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET security='u:-1;g:-1'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='vend:j3'  WHERE group_id='vend:quot'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='vend:j4'  WHERE group_id='vend:po'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='vend:j7'  WHERE group_id='vend:cm'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='cust:j9'  WHERE group_id='cust:quot'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='cust:j10' WHERE group_id='cust:so'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='cust:j12' WHERE group_id='cust:inv'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='cust:j13' WHERE group_id='cust:cm'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='cust:ltr' WHERE group_id='cust:col'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='bnk:j18'  WHERE group_id='bnk:deps'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='cust:j19' WHERE group_id='bnk:rcpt'");
                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET group_id='bnk:j20'  WHERE group_id='bnk:chk'");
                bizAutoLoad(BIZUNO_LIB."controllers/phreeform/functions.php", 'phreeformImport', 'function');
                $path = $this->myFolder.'/data/phreeform';
                $result = dbGetMulti(BIZUNO_DB_PREFIX."phreeform");
                foreach ($result as $row) {
                    switch ($row['mime_type']) {
                        case 'frm':
                        case 'rpt':
                            msgDebug("\n looking for report: $path/pf_".$row['id']);
                            $contents = @file_get_contents("$path/pf_".$row['id']);
                            if ($contents) {
                                $contents = object_to_xml(bizuno_simpleXML($contents), false, '', 0, true); // removes any CDATA containers
                                $contents = $this->convertReports($contents);
                                dbWrite(BIZUNO_DB_PREFIX."phreeform", ['doc_data'=>$contents], 'update', 'id='.$row['id']);
                                msgDebug(" ... converted!");
                            } else {
                                msgDebug(" ... no file found!");
                            }
                            $group = explode(':', $row['group_id']);
                            dbWrite(BIZUNO_DB_PREFIX."phreeform", ['group_id'=>$group[0].':rpt'], 'update', 'id='.$row['id']);
                            msgDebug("\n    Updated report id: ".$row['id']);
                            break;
                        case 'fr':
                            dbWrite(BIZUNO_DB_PREFIX."phreeform", ['group_id'=>$row['group_id'].':rpt'], 'update', 'id='.$row['id']);
                            break;
                        default:
                            dbWrite(BIZUNO_DB_PREFIX."phreeform", ['mime_type'=>'dir'], 'update', "'id={$row['id']}");
                            break;
                    }
                }
 */
                $this->moveImages($this->myFolder."data/phreeform/images", $this->myFolder."images");
                break;

            case 9: // prices modules
                if (!dbFieldExists(BIZUNO_DB_PREFIX."price_sheets", 'method')) {
                    $today  = date('Y-m-d');
                    $def_cur= dbGetValue(BIZUNO_DB_PREFIX."config_phreebooks", 'config_value', "config_key='DEFAULT_CURRENCY'");
                    // get active price_sheets and set for method 'quantity'
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."price_sheets", "inactive='0' AND effective_date<='$today' AND (expiration_date>='$today' OR expiration_date=NULL)");
                    $output = array();
                    $sIDs   = array();
                    foreach ($result as $row) {
                        // the first level needs to be adjusted as it selects the wrong source
                        $levels = explode(';', $row['default_levels']);
                        $level1 = explode(':', $levels[0]);
                        $level1[2] = $level1[2] + 1;
                        $levels[0] = implode(':', $level1);
                        $output[$row['id']] = array(
                            'type'       => $row['type'],
                            'default'    => $row['default_sheet'],
                            'title'      => $row['sheet_name'],
                            'last_update'=> $row['effective_date'],
                            'attr'       => implode(';', $levels),
                        );
                        $sIDs[$row['id']] = 0;
                    }
                    // earlier version do not have the type field
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."price_sheets", 'type')) {
                        dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."price_sheets ADD `type` CHAR(1) DEFAULT 'c' COMMENT 'tag:Type;order:30' AFTER sheet_name");
                    }
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."price_sheets
                        CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `sheet_name` `method` VARCHAR(16) DEFAULT '' COMMENT 'tag:Method;order:10',
                          ADD    `ref_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:ReferenceSheetID;order:20' AFTER `method`,
                        CHANGE `type` `contact_type` CHAR(1) DEFAULT 'c' COMMENT 'tag:Type;order:30',
                          ADD       `contact_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:ContactID;order:40' AFTER `contact_type`,
                        ADD       `inventory_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:InventoryID;order:50' AFTER `contact_id`,
                        ADD    `currency` CHAR(3) NOT NULL DEFAULT '$def_cur' COMMENT 'tag:CurrencyISO;order:60' AFTER `inventory_id`,
                        CHANGE `inactive` `inactive` ENUM('0','1') DEFAULT '0' COMMENT 'tag:Inactive;order:70',
                        ADD    `settings` TEXT DEFAULT '' COMMENT 'tag:Settings;order:80',
                        DROP revision,
                        DROP effective_date,
                        DROP expiration_date,
                        DROP default_sheet,
                        DROP default_levels");
                    if (sizeof($output) > 0) {
                        dbGetResult("TRUNCATE ".BIZUNO_DB_PREFIX."price_sheets");
                        foreach ($output as $idx => $row) {
                            $type = $row['type'];
                            unset($row['type']);
                            $sIDs[$idx] = dbWrite(BIZUNO_DB_PREFIX."price_sheets", array('method'=>'quantity', 'contact_type'=>$type, 'settings'=>json_encode($row)));
                            dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."contacts SET price_sheet="   .$sIDs[$idx]." WHERE price_sheet='"  .$row['title']."'");
                            dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."inventory SET price_sheet_c=".$sIDs[$idx]." WHERE price_sheet_c='".$row['title']."'");
                            dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."inventory SET price_sheet_v=".$sIDs[$idx]." WHERE price_sheet_v='".$row['title']."'");
                        }
                    }
                    // convert price sheet references to integers for the sheet id
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory
                        CHANGE `price_sheet_c` `price_sheet_c` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:DefPriceSheetIDSales;order:33',
                        CHANGE `price_sheet_v` `price_sheet_v` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:DefPriceSheetIDPurchase;order:35'");
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."contacts
                        CHANGE `price_sheet` `price_sheet` INT(11) NOT NULL DEFAULT '0' COMMENT 'tag:DefPriceSheetID;order:65'");
                }
                if (dbFieldExists(BIZUNO_DB_PREFIX."price_sheets", 'method') && dbTableExists(BIZUNO_DB_PREFIX."inventory_special_prices")) {
                    $sIDkeys = array_keys($sIDs);
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."inventory_special_prices", sizeof($sIDkeys)>0 ? "price_sheet_id IN (".implode(',', $sIDkeys).")" : '');
                    foreach ($result as $row) {
                        // the first level needs to be adjusted as it selects the wrong source
                        $levels = explode(';', $row['price_levels']);
                        $level1 = explode(':', $levels[0]);
                        $level1[2] = $level1[2] + 1;
                        $levels[0] = implode(':', $level1);
                        $sqlData = array(
                            'method'      => 'bySKU',
                            'ref_id'      => !empty($sIDs[$row['price_sheet_id']]) ? $sIDs[$row['price_sheet_id']] : 0,
                            'inventory_id'=> $row['inventory_id'],
                            'contact_type'=> 'c',
                            'settings'    => json_encode(array('attr'=>implode(';', $levels), 'last_update'=>date('Y-m-d'))),
                        );
                        dbWrite(BIZUNO_DB_PREFIX."price_sheets", $sqlData);
                    }
                }
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."inventory_special_prices");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."inventory_prices");
                dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."price_sheets TO ".BIZUNO_DB_PREFIX."inventory_prices");
                break;

            case 10: // shipping module
                if (dbTableExists(BIZUNO_DB_PREFIX."shipping_log")) { // table shipping_log
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."shipping_log", 'method_code')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."shipping_log
                        CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `shipment_id` `shipment_id` INT(11) DEFAULT '0' COMMENT 'tag:ShipmentID;order:5',
                        CHANGE `ref_id` `ref_id` VARCHAR(16) DEFAULT '0' COMMENT 'tag:ReferenceID;order:10',
                        ADD       `store_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:StoreID;order:13' AFTER `ref_id`,
                        CHANGE `reconciled` `reconciled` SMALLINT(4) DEFAULT '0' COMMENT 'type:checkbox;tag:Reconciled;order:15',
                        CHANGE `carrier` `method_code` VARCHAR(20) DEFAULT '' COMMENT 'type:select;tag:CarrierCode;order:20',
                        CHANGE `ship_date` `ship_date` DATETIME DEFAULT NULL COMMENT 'tag:ShipDate;order:30',
                        CHANGE `deliver_date` `deliver_date` DATETIME DEFAULT NULL COMMENT 'tag:DueDate;order:35',
                        CHANGE `actual_date` `actual_date` DATETIME DEFAULT NULL COMMENT 'tag:DeliveryDate;order:40',
                        CHANGE `deliver_late` `deliver_late` ENUM('0','1') DEFAULT '0' COMMENT 'tag:Late;order:45',
                        CHANGE `tracking_id` `tracking_id` VARCHAR(32) DEFAULT '' COMMENT 'tag:TrackingID;order:50',
                        CHANGE `cost` `cost` FLOAT DEFAULT '0' COMMENT 'format:currency;tag:Cost;order:55',
                        CHANGE `notes` `notes` VARCHAR(255) DEFAULT '' COMMENT 'tag:Notes;order:60'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='1DM' WHERE method='1DEa'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='1DM' WHERE method='1DEam'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='1DA' WHERE method='1Dam'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='1DP' WHERE method='1Dpm'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='1DF' WHERE method='1DFrt'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='2DA' WHERE method='2Dam'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='2DP' WHERE method='2Dpm'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='2DF' WHERE method='2DFrt'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='3DA' WHERE method='3Dam'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='3DP' WHERE method='3Dpm'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='3DF' WHERE method='3DFrt'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='GDF' WHERE method='GndFrt'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='ECF' WHERE method='EcoFrt'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='I1D' WHERE method='I2DEam'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='I2D' WHERE method='I2Dam'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='IGD' WHERE method='I3D'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method='IGD' WHERE method='IGND'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log set method_code='fedex' WHERE method_code='fedex_v7'");
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."shipping_log SET method_code = CONCAT(method_code, ':', method)");
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."shipping_log DROP method");
                    $date = localeCalculateDate(date('Y-m-d'), 0, -3, 0); // get unshipped for last three months
                    $result = dbGetMulti(BIZUNO_DB_PREFIX.'journal_main', "journal_id=12 AND post_date>'$date'");
                    foreach ($result as $row) {
                        $id = dbGetValue(BIZUNO_DB_PREFIX.'shipping_log', 'id', "ref_id LIKE '{$row['invoice_num']}%'");
                        if (!$id) dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."journal_main SET waiting='1' WHERE id={$row['id']}");
                    }
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."extShipping");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."shipping_log TO ".BIZUNO_DB_PREFIX."extShipping");
                }
                $constants = dbGetMulti(BIZUNO_DB_PREFIX."config_phreebooks");
                $constant = array();
                foreach ($constants as $value) $constant[$value['config_key']] = $value['config_value'];
                if (isset($constant['ADDRESS_BOOK_SHIP_CONTACT_REQ'])) {
                    $settings  = array(
                        'general' => array(
                            'gl_shipping_c'  => isset($constant['AR_DEF_FREIGHT_ACCT']) ? $constant['AR_DEF_FREIGHT_ACCT'] : '',
                            'gl_shipping_v'  => isset($constant['AP_DEF_FREIGHT_ACCT']) ? $constant['AP_DEF_FREIGHT_ACCT'] : '',
                            'contact_req'    => $constant['ADDRESS_BOOK_SHIP_CONTACT_REQ'],
                            'address1_req'   => $constant['ADDRESS_BOOK_SHIP_ADD1_REQ'],
                            'address2_req'   => $constant['ADDRESS_BOOK_SHIP_ADD2_REQ'],
                            'city_req'       => $constant['ADDRESS_BOOK_SHIP_CITY_REQ'],
                            'state_req'      => $constant['ADDRESS_BOOK_SHIP_STATE_REQ'],
                            'postal_code_req'=> $constant['ADDRESS_BOOK_SHIP_POSTAL_CODE_REQ'],
                        ),
                    );
                    dbWrite(BIZUNO_DB_PREFIX."configuration", ['config_key'=>'extShipping', 'config_value'=>json_encode(['settings'=>$settings])]);
                }
                $keys = array('ENABLE_SHIPPING_FUNCTIONS','AR_DEF_FREIGHT_ACCT','AP_DEF_FREIGHT_ACCT','ADDRESS_BOOK_SHIP_CONTACT_REQ','ADDRESS_BOOK_SHIP_ADD1_REQ',
                    'ADDRESS_BOOK_SHIP_ADD2_REQ','ADDRESS_BOOK_SHIP_CITY_REQ','ADDRESS_BOOK_SHIP_STATE_REQ','ADDRESS_BOOK_SHIP_POSTAL_CODE_REQ',
                    'SHIPPING_DEFAULT_WEIGHT_UNIT','SHIPPING_DEFAULT_CURRENCY','SHIPPING_DEFAULT_PKG_DIM_UNIT','SHIPPING_DEFAULT_RESIDENTIAL',
                    'SHIPPING_DEFAULT_PACKAGE_TYPE','SHIPPING_DEFAULT_PICKUP_SERVICE','SHIPPING_DEFAULT_LENGTH','SHIPPING_DEFAULT_WIDTH','SHIPPING_DEFAULT_HEIGHT',
                    'SHIPPING_DEFAULT_ADDITIONAL_HANDLING_SHOW','SHIPPING_DEFAULT_ADDITIONAL_HANDLING_CHECKED','SHIPPING_DEFAULT_INSURANCE_SHOW',
                    'SHIPPING_DEFAULT_INSURANCE_CHECKED','SHIPPING_DEFAULT_INSURANCE_VALUE','SHIPPING_DEFAULT_SPLIT_LARGE_SHIPMENTS_SHOW',
                    'SHIPPING_DEFAULT_SPLIT_LARGE_SHIPMENTS_CHECKED','SHIPPING_DEFAULT_SPLIT_LARGE_SHIPMENTS_VALUE','SHIPPING_DEFAULT_DELIVERY_COMFIRMATION_SHOW',
                    'SHIPPING_DEFAULT_DELIVERY_COMFIRMATION_CHECKED','SHIPPING_DEFAULT_DELIVERY_COMFIRMATION_TYPE','SHIPPING_DEFAULT_HANDLING_CHARGE_SHOW',
                    'SHIPPING_DEFAULT_HANDLING_CHARGE_CHECKED','SHIPPING_DEFAULT_HANDLING_CHARGE_VALUE','SHIPPING_DEFAULT_COD_SHOW','SHIPPING_DEFAULT_COD_CHECKED',
                    'SHIPPING_DEFAULT_PAYMENT_TYPE','SHIPPING_DEFAULT_SATURDAY_PICKUP_SHOW','SHIPPING_DEFAULT_SATURDAY_PICKUP_CHECKED',
                    'SHIPPING_DEFAULT_SATURDAY_DELIVERY_SHOW','SHIPPING_DEFAULT_SATURDAY_DELIVERY_CHECKED','SHIPPING_DEFAULT_HAZARDOUS_SHOW',
                    'SHIPPING_DEFAULT_HAZARDOUS_CHECKED','SHIPPING_DEFAULT_HAZARDOUS_MATERIAL_CHECKED','SHIPPING_DEFAULT_DRY_ICE_SHOW',
                    'SHIPPING_DEFAULT_DRY_ICE_CHECKED','SHIPPING_DEFAULT_RETURN_SERVICE_SHOW','SHIPPING_DEFAULT_RETURN_SERVICE_CHECKED',
                    'SHIPPING_DEFAULT_RETURN_SERVICE');
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key IN ('".implode("','",$keys)."')");
                break;
            case 11:
                if (dbTableExists(BIZUNO_DB_PREFIX."doc_ctl")) {
                    dbGetResult("UPDATE "     .BIZUNO_DB_PREFIX."doc_ctl SET doc_ext='dir' WHERE type<>'default'");
                    dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."doc_ctl WHERE parent_id=0");
                    dbGetResult("UPDATE "     .BIZUNO_DB_PREFIX."doc_ctl SET parent_id=0 WHERE parent_id=1");
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."doc_ctl");
                    foreach ($result as $row) {
                        $sql = array('params' => json_encode(array(
                            'ownerID'   => $row['doc_owner'],
                            'lockID'    => $row['lock_id'],
                            'checkoutID'=> $row['checkout_id'],
                        )));
                        dbWrite(BIZUNO_DB_PREFIX."doc_ctl", $sql, "update", "id=".$row['id']);
                    }
                    if (dbFieldExists(BIZUNO_DB_PREFIX."doc_ctl", 'position')) { dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."doc_ctl
                        CHANGE `id` `id` INT(10) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `parent_id` `parent_id` INT(10) DEFAULT '0' COMMENT 'type:hidden;tag:ParentID;order:10',
                        CHANGE `doc_ext` `mime_type` VARCHAR(4) DEFAULT NULL COMMENT 'type:hidden;tag:MimeType;order:20',
                        CHANGE `title` `title` VARCHAR(255) DEFAULT '' COMMENT 'tag:Title;order:30',
                        CHANGE `description` `description` VARCHAR(255) DEFAULT NULL COMMENT 'tag:Descrption;order:40',
                        CHANGE `file_name` `filename` VARCHAR(255) DEFAULT NULL COMMENT 'tag:FileName;order:50',
                        CHANGE `create_date` `create_date` DATE DEFAULT NULL COMMENT 'tag:CreateDate;order:60',
                        CHANGE `last_update` `last_update` DATE DEFAULT NULL COMMENT 'tag:LastUpdate;order:70',
                        CHANGE `security` `security` VARCHAR(255) DEFAULT 'u:0;g:0' COMMENT 'type:hidden;tag:Security;order:80',
                          CHANGE `bookmarks` `bookmarks` TEXT DEFAULT NULL COMMENT 'tag:Bookmarks;order:90',
                        CHANGE `params` `settings` TEXT DEFAULT NULL COMMENT 'tag:Settings;order:99',
                        DROP `position`, DROP `left`, DROP `right`, DROP `level`, DROP `type`, DROP `revision`,
                        DROP `doc_size`, DROP `doc_owner`, DROP `lock_id`, DROP `checkout_id`"); }
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."extDocs");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."doc_ctl TO ".BIZUNO_DB_PREFIX."extDocs");
                    $this->moveDir("doc_ctl/docs", "extDocs/uploads", "", $suffix=".dc"); // move/rename the attachments
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."extDocs SET settings='' WHERE mime_type='dir'");
                    $tmp = getModuleCache('extDocs', 'settings', false, false, []);
                    setModuleCache('extDocs', 'settings', false, $tmp);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_DOC_CTL_STATUS'");
                break;
            case 12: // work order module
                if (dbTableExists(BIZUNO_DB_PREFIX."wo_main")) {
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."srvBuilder_jobs");
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."srvBuilder_steps");
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."srvBuilder_journal");
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."srvBuilder_jsteps");
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."srvBuilder_tasks");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."wo_main TO "        .BIZUNO_DB_PREFIX."srvBuilder_jobs");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."wo_journal_main TO ".BIZUNO_DB_PREFIX."srvBuilder_journal");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."wo_task TO "        .BIZUNO_DB_PREFIX."srvBuilder_tasks");
                    dbGetResult("ALTER TABLE " .BIZUNO_DB_PREFIX."srvBuilder_jobs ENGINE=INNODB");
                    dbGetResult("ALTER TABLE " .BIZUNO_DB_PREFIX."srvBuilder_journal ENGINE=INNODB");
                    dbGetResult("ALTER TABLE " .BIZUNO_DB_PREFIX."srvBuilder_tasks ENGINE=INNODB");
                    // remove all lower revisions, just keep the latest
                    $output = array();
                    $result  = dbGetMulti(BIZUNO_DB_PREFIX."srvBuilder_jobs", '', 'revision');
                    foreach ($result as $row) {
                        $output[$row['sku_id']][] = $row['id'];
                    }
                    // now delete old revisions
                    $rmID = array();
                    foreach ($output as $sku => $value) { foreach ($value as $rev => $id) {
                        if (sizeof($output[$sku]) > ($rev+1)) { $rmID[] = $id; }
                        if (sizeof($output[$sku]) == ($rev+1)) { dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."srvBuilder_journal set wo_id=$id WHERE sku_id=$sku"); }
                    } }
                    if (sizeof($rmID)>0) { dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."srvBuilder_jobs WHERE id IN (".implode(',',$rmID).")"); }
                    dbGetResult("ALTER TABLE " .BIZUNO_DB_PREFIX."srvBuilder_jobs
                        CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `wo_title` `title` VARCHAR(32) DEFAULT '' COMMENT 'tag:Title;order:10',
                        CHANGE `inactive` `inactive` ENUM('0','1') DEFAULT '0' COMMENT 'tag:Inactive;order:20',
                        CHANGE `sku_id` `sku_id` INT(11) DEFAULT '0' COMMENT 'tag:SkuID;order:30',
                        CHANGE `description` `description` VARCHAR(64) DEFAULT '' COMMENT 'tag:Description;order:40',
                        CHANGE `allocate` `allocate`  ENUM('0','1') DEFAULT '0' COMMENT 'tag:Allocate;order:50',
                        CHANGE `ref_doc` `ref_doc`  VARCHAR(64) DEFAULT NULL COMMENT 'tag:ReferenceDocs;order:60',
                        CHANGE `ref_spec` `ref_spec`  VARCHAR(64) DEFAULT NULL COMMENT 'tag:ReferenceSpecs;order:70',
                        ADD    `steps` TEXT COMMENT 'tag:Steps;order:80' AFTER `ref_spec`,
                        CHANGE `last_usage` `date_last` DATE DEFAULT NULL COMMENT 'tag:DateLastUsed;order:95',
                        DROP revision, DROP revision_date");
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."srvBuilder_journal
                        CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `wo_id` `job_id` INT(11) DEFAULT '0' COMMENT 'type:hidden;tag:ServiceID;order:10',
                        CHANGE `wo_num` `sb_ref` VARCHAR(16) DEFAULT 'SB-00001' COMMENT 'type:hidden;tag:ReferenceID;order:20',
                        CHANGE `sku_id` `sku_id` INT(11) DEFAULT '0' COMMENT 'tag:SkuID;order:30',
                        ADD    `store_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'comment'=>'type:hidden;tag:StoreID;order:35' AFTER `sku_id`,
                        CHANGE `qty`  `qty` FLOAT DEFAULT '0' COMMENT 'tag:Quantity;order:40',
                        CHANGE `wo_title` `title` VARCHAR(32) DEFAULT NULL COMMENT 'tag:Title;order:50',
                        CHANGE `closed` `closed` ENUM('0','1') DEFAULT '0' COMMENT 'tag:Closed;order:60',
                        CHANGE `post_date` `create_date` DATE DEFAULT NULL COMMENT 'tag:CreateDate;order:70',
                        CHANGE `close_date` `close_date` DATE DEFAULT NULL COMMENT 'tag:ClosedDate;order:80',
                        ADD    `steps` TEXT COMMENT 'tag:Steps;order:90' AFTER `close_date`,
                        CHANGE `notes` `notes` TEXT COMMENT 'tag:Notes;order:95',
                        DROP   `priority`");
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."srvBuilder_tasks
                        CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `task_name` `title` VARCHAR(32) DEFAULT '' COMMENT 'tag:Title;order:10',
                        CHANGE `description` `description` VARCHAR(255) DEFAULT NULL COMMENT 'tag:Description;order:15',
                        CHANGE `ref_doc` `ref_doc` VARCHAR(64) DEFAULT NULL COMMENT 'tag:ReferenceDocs;order:20',
                        CHANGE `ref_spec` `ref_spec` VARCHAR(64) DEFAULT NULL COMMENT 'tag:ReferenceSpecs;order:25',
                        CHANGE `dept_id` `dept_id` VARCHAR(16) DEFAULT '' COMMENT 'type:select;tag:RoleID;order:30',
                        CHANGE `mfg` `mfg` ENUM('0','1') DEFAULT '0' COMMENT 'type:select;tag:ManufacturingRequired;order:45',
                        CHANGE `qa` `qa` ENUM('0','1') DEFAULT '0' COMMENT 'type:select;tag:QARequired;order:50',
                        CHANGE `data_entry` `data_entry` ENUM('0','1') DEFAULT '0' COMMENT 'type:select;tag:DataEntry;order:55',
                        CHANGE `erp_entry` `erp_entry` ENUM('0','1') DEFAULT '0' COMMENT 'type:select;tag:CompleteERP;order:60',
                        DROP job_time, DROP job_unit");
                    $output = array();
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."wo_steps", '', 'step');
                    foreach ($result as $row) {
                        $output[$row['ref_id']][$row['step']] = array('task_id'=>$row['task_id']);
                    }
                    foreach ($output as $id => $value) {
                        dbWrite(BIZUNO_DB_PREFIX."srvBuilder_jobs", array('steps'=>json_encode($value)), 'update', "id=$id");
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_WORK_ORDERS_STATUS'");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."wo_steps");
                break;

            case 13: // payment modules
                $methods = array('authorizenet', 'cod', 'directdebit', 'firstdata', 'freecharger', 'linkpoint_api', 'moneyorder', 'nova_xml', 'paymentech', 'paypal_nvp');
                $constants = dbGetMulti(BIZUNO_DB_PREFIX."config_phreebooks");
                $constant = array();
                foreach ($constants as $value) $constant[$value['config_key']] = $value['config_value'];
                foreach ($methods as $method) {
                    msgDebug("\n  Updating payment method: $method");
                    if (array_key_exists('MODULE_PAYMENT_'.strtoupper($method).'_STATUS', $constant)) { // installed
                        $result = dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."config_phreebooks SET config_key='payment_$method' WHERE config_key='MODULE_PAYMENT_".strtoupper($method)."_STATUS'");
                    } else {
                        $constant['MODULE_PAYMENT_'.strtoupper($method).'_STATUS'] = false;
                    }
                }
                $value = json_decode(dbGetValue(BIZUNO_DB_PREFIX."config_phreebooks", 'config_value', "config_key='phreebooks'"), true); // get the default gl accounts to fill settings
                $defaults = $value['settings'];
                if (array_key_exists('MODULE_PAYMENT_AUTHORIZENET_SORT_ORDER', $constant)) { // AUTHORIZENET method
                    msgDebug("\n  Updating AUTHORIZENET");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_AUTHORIZENET_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'username'     => $constant['MODULE_PAYMENT_AUTHORIZENET_LOGIN'],
                        'password'     => $constant['MODULE_PAYMENT_AUTHORIZENET_TXNKEY'],
                        'md5hash'      => $constant['MODULE_PAYMENT_AUTHORIZENET_MD5HASH'],
                        'auth_type'    => $constant['MODULE_PAYMENT_AUTHORIZENET_AUTHORIZATION_TYPE'],
                        'use_cvv'      => $constant['MODULE_PAYMENT_AUTHORIZENET_USE_CVV'],
                        'prefix'       => 'CC',
                        'mode'         => $constant['MODULE_PAYMENT_AUTHORIZENET_TESTMODE'],
                    );
                    if ($constant['MODULE_PAYMENT_AUTHORIZENET_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'authorizenet', false, []), $settings);
                        setModuleCache('payment', 'methods', 'authorizenet', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_AUTHORIZENET_%'");
                if (array_key_exists('MODULE_PAYMENT_COD_SORT_ORDER', $constant)) { // COD method
                    msgDebug("\n  Updating COD");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_COD_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'prefix'       => 'CC',
                    );
                    if ($constant['MODULE_PAYMENT_COD_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'cod', false, []), $settings);
                        setModuleCache('payment', 'methods', 'cod', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_COD_%'");
                if (array_key_exists('MODULE_PAYMENT_DIRECTDEBIT_SORT_ORDER', $constant)) { // Directdebit method
                    msgDebug("\n  Updating DIRECTDEBIT");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_DIRECTDEBIT_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'prefix'       => 'EF',
                    );
                    if ($constant['MODULE_PAYMENT_DIRECTDEBIT_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'directdebit', false, []), $settings);
                        setModuleCache('payment', 'methods', 'directdebit', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_DIRECTDEBIT_%'");
                if (array_key_exists('MODULE_PAYMENT_FIRSTDATA_SORT_ORDER', $constant)) { // FIRSTDATA method
                    msgDebug("\n  Updating FIRSTDATA");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_FIRSTDATA_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'file_config'  => $constant['MODULE_PAYMENT_FIRSTDATA_CONFIG_FILE'],
                        'file_key'     => $constant['MODULE_PAYMENT_FIRSTDATA_KEY_FILE'],
                        'host'         => $constant['MODULE_PAYMENT_FIRSTDATA_HOST'],
                        'port'         => $constant['MODULE_PAYMENT_FIRSTDATA_PORT'],
                        'auth_type'    => $constant['MODULE_PAYMENT_FIRSTDATA_AUTHORIZATION_TYPE'],
                        'prefix'       => 'CC',
                        'mode'         => $constant['MODULE_PAYMENT_FIRSTDATA_TESTMODE'],
                    );
                    if ($constant['MODULE_PAYMENT_FIRSTDATA_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'firstdata', false, []), $settings);
                        setModuleCache('payment', 'methods', 'firstdata', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_FIRSTDATA_%'");
                if (array_key_exists('MODULE_PAYMENT_FREECHARGER_SORT_ORDER', $constant)) { // COD method
                    msgDebug("\n  Updating FREECHARGER");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_FREECHARGER_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'prefix'       => 'CA',
                    );
                    if ($constant['MODULE_PAYMENT_FREECHARGER_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'freecharger', false, []), $settings);
                        setModuleCache('payment', 'methods', 'freecharger', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_FREECHARGER_%'");
                if (array_key_exists('MODULE_PAYMENT_LINKPOINT_API_SORT_ORDER', $constant)) { // LINKPOINT_API method
                    msgDebug("\n  Updating LINKPOINT_API");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_LINKPOINT_API_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'username'     => $constant['MODULE_PAYMENT_LINKPOINT_API_LOGIN'],
                        'fraud_msg'    => $constant['MODULE_PAYMENT_LINKPOINT_API_FRAUD_ALERT'],
                        'store_data'   => $constant['MODULE_PAYMENT_LINKPOINT_API_STORE_DATA'],
                        'auth_type'    => $constant['MODULE_PAYMENT_LINKPOINT_API_AUTHORIZATION_MODE'],
                        'prefix'       => 'CC',
                        'mode'         => $constant['MODULE_PAYMENT_LINKPOINT_API_TRANSACTION_MODE'],
                        'mode_response'=> $constant['MODULE_PAYMENT_LINKPOINT_API_TRANSACTION_MODE_RESPONSE'],
                        'debug'        => $constant['MODULE_PAYMENT_LINKPOINT_API_DEBUG'],
                    );
                    if ($constant['MODULE_PAYMENT_LINKPOINT_API_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'linkpoint', false, []), $settings);
                        setModuleCache('payment', 'methods', 'linkpoint', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_LINKPOINT_API_%'");
                if (array_key_exists('MODULE_PAYMENT_MONEYORDER_SORT_ORDER', $constant)) { // Moneyorder method
                    msgDebug("\n  Updating MONEYORDER");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_MONEYORDER_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'prefix'       => 'CK',
                    );
                    if ($constant['MODULE_PAYMENT_MONEYORDER_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'moneyorder', false, []), $settings);
                        setModuleCache('payment', 'methods', 'moneyorder', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_MONEYORDER_%'");
                if (array_key_exists('MODULE_PAYMENT_NOVA_XML_MERCHANT_ID', $constant)) { // Nova_xml method (becomes Elavon)
                    msgDebug("\n  Updating NOVA_XML");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_NOVA_XML_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'default'      => '1',
                        'merchant_id'  => $constant['MODULE_PAYMENT_NOVA_XML_MERCHANT_ID'],
                        'user_id'      => $constant['MODULE_PAYMENT_NOVA_XML_USER_ID'],
                        'pin'          => $constant['MODULE_PAYMENT_NOVA_XML_PIN'],
                        'auth_type'    => $constant['MODULE_PAYMENT_NOVA_XML_AUTHORIZATION_TYPE'],
                        'prefix'       => 'CC',
                        'mode'         => $constant['MODULE_PAYMENT_NOVA_XML_TESTMODE']=='Production' ? 'prod' : 'test',
                    );
                    if ($constant['MODULE_PAYMENT_NOVA_XML_STATUS']) {
                        dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='PAYMENT_NOVA_XML_STATUS'");
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'nova', false, []), $settings);
                        setModuleCache('payment', 'methods', 'nova', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_NOVA_XML_%'");
                if (array_key_exists('MODULE_PAYMENT_AUTHORIZENET_SORT_ORDER', $constant)) { // AUTHORIZENET method
                    msgDebug("\n  Updating AUTHORIZENET");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_AUTHORIZENET_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'username'     => $constant['MODULE_PAYMENT_AUTHORIZENET_LOGIN'],
                        'password'     => $constant['MODULE_PAYMENT_AUTHORIZENET_TXNKEY'],
                        'md5hash'      => $constant['MODULE_PAYMENT_AUTHORIZENET_MD5HASH'],
                        'auth_type'    => $constant['MODULE_PAYMENT_AUTHORIZENET_AUTHORIZATION_TYPE'],
                        'use_cvv'      => $constant['MODULE_PAYMENT_AUTHORIZENET_USE_CVV'],
                        'prefix'       => 'CC',
                        'mode'         => $constant['MODULE_PAYMENT_AUTHORIZENET_TESTMODE'],
                    );
                    if ($constant['MODULE_PAYMENT_AUTHORIZENET_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'authorizenet', false, []), $settings);
                        setModuleCache('payment', 'methods', 'authorizenet', $tmp);
                    }
                }
                if (array_key_exists('MODULE_PAYMENT_PAYMENTECH_SORT_ORDER', $constant)) { // PAYMENTECH method
                    msgDebug("\n  Updating PAYMENTECH");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_PAYMENTECH_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'merchID_test' => $constant['MODULE_PAYMENT_PAYMENTECH_MERCHANT_ID_TEST'],
                        'merchID_USD'  => $constant['MODULE_PAYMENT_PAYMENTECH_MERCHANT_ID_USD'],
                        'merchID_USD'  => $constant['MODULE_PAYMENT_PAYMENTECH_MERCHANT_ID_CAD'],
                        'bin'          => $constant['MODULE_PAYMENT_PAYMENTECH_BIN'],
                        'termID'       => $constant['MODULE_PAYMENT_PAYMENTECH_TERMINAL_ID'],
                        'auth_type'    => $constant['MODULE_PAYMENT_PAYMENTECH_AUTHORIZATION_TYPE'],
                        'use_cvv'      => $constant['MODULE_PAYMENT_PAYMENTECH_USE_CVV'],
                        'def_cur'      => $constant['MODULE_PAYMENT_PAYMENTECH_CURRENCIES'],
                        'prefix'       => 'CC',
                        'mode'         => $constant['MODULE_PAYMENT_PAYMENTECH_TESTMODE'],
                    );
                    if ($constant['MODULE_PAYMENT_PAYMENTECH_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'paymentech', false, []), $settings);
                        setModuleCache('payment', 'methods', 'paymentech', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_PAYMENTECH_%'");
                if (array_key_exists('MODULE_PAYMENT_PAYPAL_NVP_SORT_ORDER', $constant)) { // PayPal_nvp method
                    msgDebug("\n  Updating PAYPAL_NVP");
                    $settings  = array(
                        'order'        => $constant['MODULE_PAYMENT_PAYPAL_NVP_SORT_ORDER'],
                        'cash_gl_acct' => $defaults['customers']['gl_cash'],
                        'disc_gl_acct' => $defaults['customers']['gl_discount'],
                        'username'     => $constant['MODULE_PAYMENT_PAYPAL_NVP_USER_ID'],
                        'password'     => $constant['MODULE_PAYMENT_PAYPAL_NVP_PW'],
                        'signature'    => $constant['MODULE_PAYMENT_PAYPAL_NVP_SIG'],
                        'auth_type'    => $constant['MODULE_PAYMENT_PAYPAL_NVP_AUTHORIZATION_TYPE'],
                        'prefix'       => 'CC',
                        'mode'         => $constant['MODULE_PAYMENT_PAYPAL_NVP_TESTMODE'],
                    );
                    if ($constant['MODULE_PAYMENT_PAYPAL_NVP_STATUS']) {
                        $tmp = array_merge(getModuleCache('payment', 'methods', 'paypal', false, []), $settings);
                        setModuleCache('payment', 'methods', 'paypal', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks where config_key like 'MODULE_PAYMENT_PAYPAL_NVP_%'");
                break;
            case 14: // shipping methods
                $methods = array('freeshipper', 'flat', 'fedex_v7', 'endicia', 'item', 'storepickup', 'table', 'usps', 'ups', 'yrc');
                $constants = dbGetMulti(BIZUNO_DB_PREFIX."config_phreebooks");
                $constant = array();
                foreach ($constants as $value) $constant[$value['config_key']] = $value['config_value'];
                foreach ($methods as $method) {
                    msgDebug("\n  Updating shipping method: $method");
                    if (array_key_exists('MODULE_SHIPPING_'.strtoupper($method).'_STATUS', $constant)) { // installed
                        $result = dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."config_phreebooks SET config_key='extShipping_$method' WHERE config_key='MODULE_SHIPPING_".strtoupper($method)."_STATUS'");
                    } else {
                        $constant['MODULE_SHIPPING_'.strtoupper($method).'_STATUS'] = false;
                    }
                }
                $value = json_decode(dbGetValue(BIZUNO_DB_PREFIX."config_phreebooks", 'config_value', "config_key='extShipping'"), true); // get the default gl accounts to fill settings
                $defaults = $value['settings'];
                if (array_key_exists('MODULE_SHIPPING_FEDEX_V7_SORT_ORDER', $constant)) { // FEDEX_V7 method
                    msgDebug("\n  Updating FEDEX_V7");
                    $services = str_replace(array('1DEam','1Dam','1Dpm','1DFrt','2Dam','2Dpm','2DFrt','3Dam','3Dpm','3DFrt','GndFrt','EcoFrt','I2DEam','I2DA','I3D'),
                                            array('1DM',  '1DA', '1DP', '1DF',  '2DA', '2DP', '2DF',  '3DA', '3DP', '3DF',  'GDF',   'ECF',   'I1D',   'I2D', 'IGD'), $constant['MODULE_SHIPPING_FEDEX_V7_TYPES']);
                    $services = str_replace(',', ':', $services);
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_FEDEX_V7_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'default'      => '1',
                        'acct_number'  => $constant['MODULE_SHIPPING_FEDEX_V7_ACCOUNT_NUMBER'],
                        'ltl_acct_num' => $constant['MODULE_SHIPPING_FEDEX_V7_LTL_ACCOUNT_NUMBER'],
                        'auth_key'     => $constant['MODULE_SHIPPING_FEDEX_V7_AUTH_KEY'],
                        'auth_pw'      => $constant['MODULE_SHIPPING_FEDEX_V7_AUTH_PWD'],
                        'meter_number' => $constant['MODULE_SHIPPING_FEDEX_V7_METER_NUMBER'],
                        'sp_hub'       => '', // 5802 for Denver, CO
                        'test_mode'    => $constant['MODULE_SHIPPING_FEDEX_V7_TEST_MODE'],
                        'printer_type' => $constant['MODULE_SHIPPING_FEDEX_V7_PRINTER_TYPE'],
                        'printer_name' => $constant['MODULE_SHIPPING_FEDEX_V7_PRINTER_NAME'],
                        'service_types'=> $services,
                        'max_weight'   => '150',
                        'max_sp_weight'=> '7',
                        'label_pdf'    => 'PAPER_8.5X11_TOP_HALF_LABEL',
                        'label_thermal'=> 'STOCK_4X6.75_LEADING_DOC_TAB',
                        'recon_fee'    => '3.00',
                        'recon_percent'=> '0.1',
                    );
                    if ($constant['MODULE_SHIPPING_FEDEX_V7_STATUS']) {
                        dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='SHIPPING_FEDEX_V7_STATUS'");
                        $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'fedex', false, []), $settings);
                        setModuleCache('extShipping', 'carriers', 'fedex', $tmp);
                    }
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."journal_main SET method_code=REPLACE(method_code, 'fedex_v7', 'fedex') WHERE method_code LIKE '%fedex_v7:%'");
                    $this->moveDir("shipping/labels/fedex_v7", "shipping/labels/fedex", false, false);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_FEDEX_V7_%'");
                if (array_key_exists('MODULE_SHIPPING_ENDICIA_SORT_ORDER', $constant)) { // ENDICIA method
                    msgDebug("\n  Updating ENDICIA");
                    $services = str_replace(array('1DEam','1Dam','1Dpm','1DFrt','2Dam','2Dpm','2DFrt','3Dam','3Dpm','3DFrt','GndFrt','EcoFrt','I2DEam','I2Dam','IGND'),
                                            array('1DM',  '1DA', '1DP', '1DF',  '2DA', '2DP', '2DF',  '3DA', '3DP', '3DF',  'GDF',   'ECF',   'I1D',   'I2D',  'IGD'), $constant['MODULE_SHIPPING_ENDICIA_TYPES']);
                    $services = str_replace(',', ':', $services);
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_ENDICIA_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'default'      => '1',
                        'acct_number'  => $constant['MODULE_SHIPPING_ENDICIA_ACCOUNT_NUMBER'],
                        'auth_pass'    => $constant['MODULE_SHIPPING_ENDICIA_PASS_PHRASE'],
                        'test_mode'    => $constant['MODULE_SHIPPING_ENDICIA_TEST_MODE'],
                        'printer_type' => $constant['MODULE_SHIPPING_ENDICIA_PRINTER_TYPE'],
                        'printer_name' => $constant['MODULE_SHIPPING_ENDICIA_PRINTER_NAME'],
                        'service_types'=> $services,
                        'label_thermal'=> 'DocTab',
                    );
                    if ($constant['MODULE_SHIPPING_ENDICIA_STATUS']) {
                        $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'endicia', false, []), $settings);
                        setModuleCache('extShipping', 'carriers', 'endicia', $tmp);
                    }
                    dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."extShipping SET ref_id=CONCAT(ref_id, '-1') WHERE method_code LIKE '%endicia:%'");
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_ENDICIA_%'");
                if (array_key_exists('MODULE_SHIPPING_FLAT_SORT_ORDER', $constant)) { // FLAT method
                    msgDebug("\n  Updating FLAT");
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_FLAT_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'rate'         => $constant['MODULE_SHIPPING_FLAT_COST'],
                        'service_types'=> 'GND',
                        'default'      => '1',
                    );
                    if ($constant['MODULE_SHIPPING_FLAT_STATUS']) {
                        $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'flat', false, []), $settings);
                        setModuleCache('extShipping', 'carriers', 'flat', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_FLAT_%'");
                if (array_key_exists('MODULE_SHIPPING_FREESHIPPER_SORT_ORDER', $constant)) { // FREESHIPPER method
                    msgDebug("\n  Updating FREESHIPPER");
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_FREESHIPPER_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'rate'         => $constant['MODULE_SHIPPING_FREESHIPPER_COST']+$constant['MODULE_SHIPPING_FREESHIPPER_HANDLING'],
                        'service_types'=> 'GND',
                        'default'      => '1',
                    );
                    if ($constant['MODULE_SHIPPING_FREESHIPPER_STATUS']) {
                        $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'freeshipper', false, []), $settings);
                        setModuleCache('extShipping', 'carriers', 'freeshipper', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_FREESHIPPER_%'");
                if (array_key_exists('MODULE_SHIPPING_ITEM_SORT_ORDER', $constant)) { // ITEM method
                    msgDebug("\n  Updating ITEM");
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_ITEM_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'rate'         => $constant['MODULE_SHIPPING_ITEM_COST']+$constant['MODULE_SHIPPING_ITEM_HANDLING'],
                        'service_types'=> 'GND',
                        'default'      => '1',
                    );
                    if ($constant['MODULE_SHIPPING_ITEM_STATUS']) {
                        $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'item', false, []), $settings);
                        setModuleCache('extShipping', 'carriers', 'item', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_ITEM_%'");
                if (array_key_exists('MODULE_SHIPPING_STOREPICKUP_SORT_ORDER', $constant)) { // STOREPICKUP method
                    msgDebug("\n  Updating STOREPICKUP");
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_STOREPICKUP_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'rate'         => $constant['MODULE_SHIPPING_STOREPICKUP_COST'],
                        'service_types'=> 'GND',
                        'default'      => '1',
                    );
                    if ($constant['MODULE_SHIPPING_STOREPICKUP_STATUS']) {
                        $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'willcall', false, []), $settings);
                        setModuleCache('extShipping', 'carriers', 'willcall', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_STOREPICKUP_%'");
                if (array_key_exists('MODULE_SHIPPING_TABLE_SORT_ORDER', $constant)) { // TABLE method
                    msgDebug("\n  Updating TABLE");
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_TABLE_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'rate'         => $constant['MODULE_SHIPPING_TABLE_COST'],
                        'mode'         => $constant['MODULE_SHIPPING_TABLE_MODE'],
                        'handling'     => $constant['MODULE_SHIPPING_TABLE_HANDLING'],
                        'service_types'=> 'GND',
                        'default'      => '1',
                    );
                    if ($constant['MODULE_SHIPPING_TABLE_STATUS']) {
                        $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'table', false, []), $settings);
                        setModuleCache('extShipping', 'carriers', 'table', $tmp);
                    }
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_TABLE_%'");
                if (array_key_exists('MODULE_SHIPPING_UPS_SORT_ORDER', $constant)) { // UPS method
                    msgDebug("\n  Updating UPS");
                    $services = str_replace(array('1DEam','1Dam','1Dpm','1DFrt','2Dam','2Dpm','2DFrt','3Dam','3Dpm','3DFrt','GndFrt','EcoFrt','I2DEam','I2Dam','IGND','I2DA','I3D'),
                                            array('1DM',  '1DA', '1DP', '1DF',  '2DA', '2DP', '2DF',  '3DA', '3DP', '3DF',  'GDF',   'ECF',   'I1D',   'I2D',  'IGD' ,'I2D', 'IGD'), $constant['MODULE_SHIPPING_UPS_TYPES']);
                    $services = str_replace(',', ':', $services);
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_UPS_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'default'      => '1',
                        'acct_number'  => $constant['MODULE_SHIPPING_UPS_SHIPPER_NUMBER'],
                        'user_id'      => $constant['MODULE_SHIPPING_UPS_USER_ID'],
                        'user_pw'      => $constant['MODULE_SHIPPING_UPS_PASSWORD'],
                        'access_key'   => $constant['MODULE_SHIPPING_UPS_ACCESS_KEY'],
                        'test_mode'    => $constant['MODULE_SHIPPING_UPS_TEST_MODE'],
                        'printer_type' => $constant['MODULE_SHIPPING_UPS_PRINTER_TYPE'],
                        'printer_name' => $constant['MODULE_SHIPPING_UPS_PRINTER_NAME'],
                        'service_types'=> $services,
                        'label_thermal'=> $constant['MODULE_SHIPPING_UPS_LABEL_SIZE'],
                    );
                    $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'ups', false, []), $settings);
                    setModuleCache('extShipping', 'carriers', 'ups', $tmp);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_UPS_%'");
                if (array_key_exists('MODULE_SHIPPING_USPS_SORT_ORDER', $constant)) { // USPS method
                    msgDebug("\n  Updating USPS");
                    $services = str_replace(array('1DEam','1Dam','1Dpm','1DFrt','2Dam','2Dpm','2DFrt','3Dam','3Dpm','3DFrt','GndFrt','EcoFrt','I2DEam','I2Dam','IGND'),
                            array('1DM',  '1DA', '1DP', '1DF',  '2DA', '2DP', '2DF',  '3DA', '3DP', '3DF',  'GDF',   'ECF',   'I1D',   'I2D',  'IGD'), $constant['MODULE_SHIPPING_USPS_TYPES']);
                    $services = str_replace(",", ":", $services);
                    $settings = array(
                        'order'        => $constant['MODULE_SHIPPING_USPS_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'default'      => '1',
                        'user_id'      => $constant['MODULE_SHIPPING_USPS_USERID'],
                        'test_mode'    => $constant['MODULE_SHIPPING_USPS_SERVER'],
                        'service_types'=> $services,
                        'machinable'=> $constant['MODULE_SHIPPING_USPS_MACHINABLE'],
                    );
                    $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'usps', false, []), $settings);
                    setModuleCache('extShipping', 'carriers', 'usps', $tmp);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_USPS_%'");
                if (array_key_exists('MODULE_SHIPPING_YRC_SORT_ORDER', $constant)) { // YRC method
                    msgDebug("\n  Updating YRC");
                    $settings  = array(
                        'order'        => $constant['MODULE_SHIPPING_YRC_SORT_ORDER'],
                        'gl_acct_c'    => $defaults['general']['gl_shipping_c'],
                        'gl_acct_v'    => $defaults['general']['gl_shipping_v'],
                        'default'      => '1',
                        'acct_number'  => $constant['MODULE_SHIPPING_YRC_BUSID'],
                        'user_id'      => $constant['MODULE_SHIPPING_YRC_USER_ID'],
                        'user_pw'      => $constant['MODULE_SHIPPING_YRC_PASSWORD'],
                    );
                    $tmp = array_merge(getModuleCache('extShipping', 'carriers', 'yrc', false, []), $settings);
                    setModuleCache('extShipping', 'carriers', 'yrc', $tmp);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_SHIPPING_YRC_%'");
                //     Fix the shipper codes in the journal_main table to map to new codes
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '1DEam', '1DM') WHERE method_code LIKE '%:1DEam%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '1Dam',  '1DA') WHERE method_code LIKE '%:1Dam%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '1Dpm',  '1DP') WHERE method_code LIKE '%:1Dpm%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '1DFrt', '1DF') WHERE method_code LIKE '%:1DFrt%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '2Dam',  '2DA') WHERE method_code LIKE '%:2Dam%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '2Dpm',  '2DP') WHERE method_code LIKE '%:2Dpm%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '2DFrt', '2DF') WHERE method_code LIKE '%:2DFrt%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '3Dam',  '3DA') WHERE method_code LIKE '%:3Dam%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '3Dpm',  '3DP') WHERE method_code LIKE '%:3Dpm%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, '3DFrt', '3DF') WHERE method_code LIKE '%:3DFrt%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, 'GndFrt','GDF') WHERE method_code LIKE '%:GndFrt%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, 'EcoFrt','ECF') WHERE method_code LIKE '%:EcoFrt%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, 'I2DEam','I1D') WHERE method_code LIKE '%:I2DEam%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, 'I2Dam', 'I2D') WHERE method_code LIKE '%:I2Dam%'");
                dbGetResult("update ".BIZUNO_DB_PREFIX."journal_main set method_code=REPLACE(method_code, 'IGND',  'IGD') WHERE method_code LIKE '%:IGND%'");
                break;
            case 15: // RMA module, Asset module, capa Module, Receiving module (probably just delete table)
                if (dbTableExists(BIZUNO_DB_PREFIX."rma_module") && !dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'return_num')) {
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'purch_order_id')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module ADD `purch_order_id` varchar(24) DEFAULT NULL");
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'contact_id'))      dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module ADD `contact_id` varchar(32) DEFAULT NULL");
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'contact_name'))      dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module ADD `contact_name` varchar(32) DEFAULT NULL");
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'receive_details'))dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module ADD `receive_details` varchar(32) DEFAULT NULL");
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'close_notes'))      dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module ADD `close_notes` varchar(32) DEFAULT NULL");
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'close_details'))  dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module ADD `close_details` varchar(32) DEFAULT NULL");
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'invoice_date'))      dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module ADD `invoice_date` varchar(32) DEFAULT NULL");
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."rma_module", 'attachments'))      dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module ADD `attachments` varchar(32) DEFAULT NULL");
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."rma_module
                        CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `rma_num` `return_num` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'tag:ReturnNumber;order:5',
                        CHANGE `status` `status` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:StatusCode;order:10',
                        CHANGE `entered_by` `entered_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:EnteredBy;order:15',
                        CHANGE `return_code` `code` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:ReturnCode;order:20',
                        CHANGE `purchase_invoice_id` `invoice_num` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:InvoiceNumber;order:25',
                        CHANGE `purch_order_id` `purch_order_id` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:PurchaseOrderNumber;order:30',
                        CHANGE `caller_name` `caller_name` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:CallerName;order:35',
                        CHANGE `caller_telephone1` `telephone` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:CallerTelephone;order:40',
                        CHANGE `caller_email` `email` VARCHAR(48) NOT NULL DEFAULT '' COMMENT 'tag:CallerEmail;order:45',
                        CHANGE `caller_notes` `notes` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'type:textarea;tag:CallerNotes;order:50',
                        CHANGE `contact_id` `contact_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:ContactID;order:55',
                        CHANGE `contact_name` `contact_name` VARCHAR(48) NOT NULL DEFAULT '' COMMENT 'tag:ContactName;order:60',
                        CHANGE `received_by` `received_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:ReceivedByContactID;order:65',
                        CHANGE `receive_carrier` `receive_carrier` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:ReceivedCarrier;order:70',
                        CHANGE `receive_tracking` `receive_tracking` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:ReceivedTracking;order:75',
                        CHANGE `receive_notes` `receive_notes` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'type:textarea;tag:ReceivedNotes;order:80',
                        CHANGE `receive_details` `receive_details` TEXT COMMENT 'tag:ReceivedDetails;order:85',
                        ADD    `closed_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:ClosedBy;order:90' AFTER `receive_details`,
                        CHANGE `close_notes` `close_notes` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'type:textarea;tag:CloseNotes;order:95',
                        CHANGE `close_details` `close_details` TEXT COMMENT 'tag:CloseDetails;order:100',
                        CHANGE `creation_date` `creation_date` DATE DEFAULT NULL COMMENT 'tag:DateCreated;order:105',
                        CHANGE `invoice_date` `invoice_date` DATE DEFAULT NULL COMMENT 'tag:DateInvoiced;order:110',
                        CHANGE `receive_date` `receive_date` DATE DEFAULT NULL COMMENT 'tag:DateReceived;order:115',
                        CHANGE `closed_date` `closed_date` DATE DEFAULT NULL COMMENT 'tag:DateClosed;order:120',
                        CHANGE `attachments` `attach` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'tag:Attachment;order:125'");
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."extReturns");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."rma_module TO ".BIZUNO_DB_PREFIX."extReturns");
                    //dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."rma_module_item");
                    $this->moveDir("rma/main", "extReturns/uploads", "rma_", $suffix=".zip"); // move/rename the attachments
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_RMA_STATUS'");
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_EXTRETURNS_STATUS'");
                // assets module
                if (dbTableExists(BIZUNO_DB_PREFIX."assets") && !dbFieldExists(BIZUNO_DB_PREFIX."assets", 'asset_num')) {
                    if (!dbFieldExists(BIZUNO_DB_PREFIX."assets", 'attachments')) {
                        dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."assets ADD `attachments` varchar(1) DEFAULT NULL");
                    }
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."assets
                        CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `asset_id` `asset_num` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:FixedAssetNumber;order:5',
                        CHANGE `description_short` `title` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Title;order:10',
                        CHANGE `description_long` `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'type:textarea;tag:Description;order:15',
                        CHANGE `inactive` `status` CHAR(1) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:StatusCode;order:20',
                        ADD    `store_id` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:hidden;tag:StoreID;order:25' AFTER `status`,
                        CHANGE `attachments` `attach` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'tag:Attachment;order:30',
                        CHANGE `asset_type` `type` CHAR(2) NOT NULL DEFAULT '' COMMENT 'type:select;tag:Type;order:35',
                        CHANGE `purch_cond` `purch_cond` ENUM('n','u') NOT NULL DEFAULT 'n' COMMENT 'tag:PurchaseCondition;order:40',
                        CHANGE `serial_number` `serial_number` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:SerialNumber;order:45',
                        CHANGE `image_with_path` `image_with_path` VARCHAR(255) DEFAULT NULL  COMMENT 'tag:Image;order:50',
                        CHANGE `account_asset` `gl_asset` VARCHAR(15) DEFAULT NULL COMMENT 'type:select;tag:GLAccountAsset;order:55',
                        CHANGE `account_depreciation` `gl_dep` VARCHAR(15) DEFAULT NULL COMMENT 'type:select;tag:GLAccountDepreciation;order:60',
                        CHANGE `account_maintenance` `gl_maint`  VARCHAR(15) DEFAULT NULL COMMENT 'type:select;tag:GLAccountMaintenance;order:65',
                        CHANGE `asset_cost` `cost` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:ItemCost;order:70',
                        CHANGE `acquisition_date` `date_acq` DATETIME DEFAULT NULL COMMENT 'tag:DateAcquired;order:75',
                        CHANGE `maintenance_date` `date_maint` DATETIME DEFAULT NULL COMMENT 'tag:DateLastMaintained;order:80',
                        CHANGE `terminal_date` `date_retire` DATETIME DEFAULT NULL COMMENT 'tag:DateRetired;order:85',
                        ADD    `dep_sched` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'type:select;tag:Schedules;order:90' AFTER `terminal_date`,
                        ADD    `dep_value` FLOAT NOT NULL DEFAULT '0' COMMENT 'tag:DepreciatedValue;order:95' AFTER `dep_sched`");
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."extFixedAssets");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."assets TO ".BIZUNO_DB_PREFIX."extFixedAssets");
                    $dirSrc = "data/assets/images";
                    $dirDest= "data/extFixedAssets/images";
                    $io->folderCopy($dirSrc, $dirDest);
                    if ($handle = @opendir($dirSrc)) {
                        while (false !== ($fileName = readdir($handle))) if (!in_array($fileName, array('.', '..'))) @unlink($this->myFolder."data/assets/images/$fileName");
                        closedir($handle);
                        @rmdir($this->myFolder."data/assets/images");
                        $this->moveDir("assets/main", "extFixedAssets/uploads", "assets_", $suffix=".zip"); // move/rename the attachments
                    }
                    $tmp = getModuleCache('extFixedAssets', 'settings', false, false, []);
                    setModuleCache('extFixedAssets', 'settings', false, $tmp);
                }
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."assets_fields");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."assets_tabs");
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_EXTFIXEDASSETS_STATUS'");
                // capa_module
                if (dbTableExists(BIZUNO_DB_PREFIX."capa_module") && !dbFieldExists(BIZUNO_DB_PREFIX."capa_module", 'qa_num')) {
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."capa_module
                        CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                        CHANGE `capa_num` `qa_num` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'tag:QANumber;order:5',
                        CHANGE `capa_status` `status` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:StatusCode;order:10',
                        CHANGE `capa_type` `type` ENUM('c','p') NOT NULL DEFAULT 'c' COMMENT 'type:select;tag:Type;order:15',
                        CHANGE `capa_closed` `closed` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'type:select;tag:Closed;order:20',
                        CHANGE `requested_by` `requested_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:RequestedBy;order:25',
                        CHANGE `entered_by` `entered_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:EnteredBy;order:30',
                        CHANGE `creation_date` `creation_date` DATE DEFAULT NULL COMMENT 'tag:DateCreated;order:35',
                        CHANGE `notes_issue` `issue_notes` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'tag:IssueNotes;order:40',
                        CHANGE `customer_id` `contact_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:ContactID;order:45',
                        CHANGE `customer_name` `contact_name` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:ContactName;order:50',
                        CHANGE `customer_invoice` `invoice_num` VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'tag:InvoiceNumber;order:55',
                        CHANGE `customer_telephone` `telephone` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Telephone;order:60',
                        CHANGE `customer_email` `email` VARCHAR(48) NOT NULL DEFAULT '' COMMENT 'tag:Email;order:65',
                        CHANGE `notes_customer` `contact_notes` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'tag:ContactNotes;order:70',
                        CHANGE `analyze_due_id` `analyze_start_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:AnalyzeStartID;order:75',
                        CHANGE `analyze_due` `analyze_start_date` DATE DEFAULT NULL COMMENT 'type:date;tag:AnalyzeStartDate;order:80',
                        CHANGE `analyze_close_id` `analyze_end_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:AnalyzeEndID;order:85',
                        CHANGE `analyze_date` `analyze_end_date` DATE DEFAULT NULL COMMENT 'type:date;tag:AnalyzeEndDate;order:90',
                        CHANGE `repair_due_id` `repair_start_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:RepairStartID;order:95',
                        CHANGE `repair_due` `repair_start_date` DATE DEFAULT NULL COMMENT 'type:date;tag:RepairStartDate;order:100',
                        CHANGE `repair_close_id` `repair_end_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:RepairEndID;order:105',
                        CHANGE `repair_date` `repair_end_date` DATE DEFAULT NULL COMMENT 'type:date;tag:RepairEndDate;order:110',
                        CHANGE `audit_due_id` `audit_start_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:AuditStartID;order:115',
                        CHANGE `audit_due` `audit_start_date` DATE DEFAULT NULL COMMENT 'type:date;tag:AuditStartDate;order:120',
                        CHANGE `audit_close_id` `audit_end_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:AuditEndID;order:125',
                        CHANGE `audit_date` `audit_end_date` DATE DEFAULT NULL COMMENT 'type:date;tag:AuditEndDate;order:130',
                        CHANGE `closed_due_id` `close_start_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:CloseStartID;order:135',
                        CHANGE `closed_due` `close_start_date` DATE DEFAULT NULL COMMENT 'type:date;tag:CloseStartDate;order:140',
                        CHANGE `closed_close_id` `close_end_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:CloseEndID;order:145',
                        CHANGE `closed_date` `close_end_date` DATE DEFAULT NULL COMMENT 'type:date;tag:CloseEndDate;order:150',
                        CHANGE `agreed_by` `action_by` INT(11) NOT NULL DEFAULT '0' COMMENT 'type:select;tag:ActionID;order:155',
                        CHANGE `action_date` `action_date` DATE DEFAULT NULL COMMENT 'type:date;tag:ActionDate;order:160',
                        CHANGE `notes_investigation` `notes` TEXT COMMENT 'type:textarea;tag:Notes;order:165',
                        CHANGE `notes_action` `action_notes` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'tag:ActionNotes;order:170',
                        CHANGE `next_capa_num` `ref_qa_num` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'tag:LinkQANumber;order:175',
                        CHANGE `notes_audit` `audit_notes` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'tag:AuditNotes;order:180',
                        CHANGE `attachments` `attach` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'tag:Attachment;order:185'");
                    dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."current_status CHANGE `next_capa_num` `next_qa_num` VARCHAR(16) NOT NULL DEFAULT 'QA0001' COMMENT 'label:Next QA Number'");
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."extQuality");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."capa_module TO ".BIZUNO_DB_PREFIX."extQuality");
                    $tmp = getModuleCache('extQuality', 'settings', false, false, []);
                    setModuleCache('extQuality', 'settings', false, $tmp);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_CP_ACTION_STATUS'");
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_EXTQUALITY_STATUS'");
                break;
            case 16: // other clean up
                if (dbTableExists(BIZUNO_DB_PREFIX."countries"))      dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."countries");
                if (dbTableExists(BIZUNO_DB_PREFIX."import_export"))  dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."import_export");
                if (dbTableExists(BIZUNO_DB_PREFIX."project_version"))dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."project_version");
                if (dbTableExists(BIZUNO_DB_PREFIX."reports"))        dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."reports");
                if (dbTableExists(BIZUNO_DB_PREFIX."report_fields"))  dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."report_fields");
                if (dbTableExists(BIZUNO_DB_PREFIX."rma_module_item"))dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."rma_module_item");
                if (dbTableExists(BIZUNO_DB_PREFIX."zones"))          dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."zones");
                if (dbTableExists(BIZUNO_DB_PREFIX."zh_config"))      dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."zh_config");
                if (dbTableExists(BIZUNO_DB_PREFIX."zh_glossary"))    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."zh_glossary");
                if (dbTableExists(BIZUNO_DB_PREFIX."zh_index"))       dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."zh_index");
                if (dbTableExists(BIZUNO_DB_PREFIX."zh_search"))      dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."zh_search");
                // OpenCart conversion
                $constants = dbGetMulti(BIZUNO_DB_PREFIX."config_phreebooks");
                $constant = array();
                foreach ($constants as $value) $constant[$value['config_key']] = $value['config_value'];
                if (isset($constant['ENCRYPTION_VALUE']) && $constant['ENCRYPTION_VALUE']) { //  Encryption Key
                    setModuleCache('bizuno', 'encKey', false, $constant['ENCRYPTION_VALUE']);
                }
                if (isset($constant['MODULE_OPENCART_STATUS'])) { //  OpenCart module
                    msgDebug("\n  Updating OPENCART");
                    $settings  = array('production'=>array(
                        'url'           => $constant['OPENCART_URL'],
                        'username'      => $constant['OPENCART_USERNAME'],
                        'password'      => $constant['OPENCART_PASSWORD'],
                        'tax_id'        => isset($constant['OPENCART_PRODUCT_TAX_CLASS']) ? $constant['OPENCART_PRODUCT_TAX_CLASS'] : '',
                        'price_sheet'   => isset($constant['OPENCART_PRICE_SHEET'])       ? $constant['OPENCART_PRICE_SHEET'] : '',
                        'status_confirm'=> isset($constant['OPENCART_STATUS_CONFIRM_ID']) ? $constant['OPENCART_STATUS_CONFIRM_ID'] : '',
                        'status_partial'=> isset($constant['OPENCART_STATUS_PARTIAL_ID']) ? $constant['OPENCART_STATUS_PARTIAL_ID'] : '',
                    ));
                    $tmp = array_merge(getModuleCache('ifOpenCart', 'settings'), $settings);
                    setModuleCache('ifOpenCart', 'settings', false, $tmp);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'OPENCART_%'");
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_OPENCART_STATUS'");
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key='MODULE_IFOPENCART_STATUS'");
                // PhreePOS module
                if (isset($constant['MODULE_PHREEPOS_STATUS'])) {
                    msgDebug("\n  Updating PhreePOS");
                    $settings  = array('general'=>array(
                        'discount'    => isset($constant['PHREEPOS_DISCOUNT_OF'])                  ? $constant['PHREEPOS_DISCOUNT_OF'] : '',
                        'with_tax'    => isset($constant['PHREEPOS_DISPLAY_WITH_TAX'])             ? $constant['PHREEPOS_DISPLAY_WITH_TAX'] : '',
                        'direct_print'=> isset($constant['PHREEPOS_ENABLE_DIRECT_PRINTING'])       ? $constant['PHREEPOS_ENABLE_DIRECT_PRINTING'] : '',
                        'drawer_open' => isset($constant['PHREEPOS_RECEIPT_PRINTER_OPEN_DRAWER'])  ? $constant['PHREEPOS_RECEIPT_PRINTER_OPEN_DRAWER'] : '',
                        'print_start' => isset($constant['PHREEPOS_RECEIPT_PRINTER_STARTING_LINE'])? $constant['PHREEPOS_RECEIPT_PRINTER_STARTING_LINE'] : '',
                        'print_end'   => isset($constant['PHREEPOS_RECEIPT_PRINTER_CLOSING_LINE']) ? $constant['PHREEPOS_RECEIPT_PRINTER_CLOSING_LINE'] : '',
                        'print_title' => isset($constant['PHREEPOS_RECEIPT_PRINTER_NAME'])         ? $constant['PHREEPOS_RECEIPT_PRINTER_NAME'] : '',
                        'rounding'    => isset($constant['PHREEPOS_ROUNDING'])                     ? $constant['PHREEPOS_ROUNDING'] : '',
                    ));
                    $tmp = array_merge(getModuleCache('extBizPOS', 'settings', false, false, []), $settings);
                    setModuleCache('extBizPOS', 'settings', false, $tmp);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'PHREEPOS_%'");
                // zencart module
                if (isset($constant['MODULE_ZENCART_STATUS'])) { //  ZenCart module
                    msgDebug("\n  Updating ZENCART");
                    $settings  = array('general'=>array(
                        'zencart_url' => $constant['ZENCART_URL'],
                        'user_id'     => $constant['ZENCART_USERNAME'],
                        'user_pw'     => $constant['ZENCART_PASSWORD'],
                        'tax_class'   => $constant['ZENCART_PRODUCT_TAX_CLASS'],
                        'price_sheet' => $constant['ZENCART_PRICE_SHEET'],
                        'confirm_id'  => $constant['ZENCART_STATUS_CONFIRM_ID'],
                        'partial_id'  => $constant['ZENCART_STATUS_PARTIAL_ID'],
                    ));
                    $tmp = array_merge(getModuleCache('ifZenCart', 'settings', false, false, []), $settings);
                    setModuleCache('ifZenCart', 'settings', false, $tmp);
                }
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_ZENCART_%'");
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'ZENCART_%'");
                // We need to find the default currency from the prior install and set it in the upgrade
/*              if (!dbFieldExists(BIZUNO_DB_PREFIX."currencies", 'id')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."currencies
                    CHANGE `currencies_id` `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'type:hidden;tag:RecordID;order:1',
                    CHANGE `title` `title` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'tag:Title;order:10',
                    CHANGE `code` `code` CHAR(3) NOT NULL DEFAULT '' COMMENT 'tag:Code;order:15',
                    CHANGE `symbol_left` `symbol_left` VARCHAR(24) DEFAULT NULL COMMENT 'tag:SymbolLeft;order:20',
                    CHANGE `symbol_right` `symbol_right` VARCHAR(24) DEFAULT NULL COMMENT 'tag:SymbolRight;order:25',
                    CHANGE `decimal_point` `decimal_point` CHAR(1) DEFAULT NULL COMMENT 'tag:DecimalPoint;order:30',
                    CHANGE `thousands_point` `thousands_point` CHAR(1) DEFAULT NULL COMMENT 'tag:ThousandsPoint;order:35',
                    CHANGE `decimal_places` `decimal_places` CHAR(1) NOT NULL DEFAULT '2' COMMENT 'tag:DecimalPlaces;order:40',
                    CHANGE `decimal_precise` `decimal_precise` CHAR(1) NOT NULL DEFAULT '2' COMMENT 'tag:DecimalPrecise;order:45',
                    CHANGE `value` `value` DOUBLE DEFAULT NULL COMMENT 'tag:Value;order:50',
                    CHANGE `last_updated` `last_update` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'tag:Last Update;order:55',
                    ADD `is_default` ENUM('0','1') NOT NULL DEFAULT '0' COMMENT 'tag:Default;order:60'"); */
                $curDefault = dbGetValue(BIZUNO_DB_PREFIX."config_phreebooks", 'config_value', "config_key='DEFAULT_CURRENCY'");
//                $currency   = array('default' => $curDefault);
                $iso        = dbGetMulti(BIZUNO_DB_PREFIX."currencies");
                $currencies = [];
                foreach ($iso as $row) {
                    $currencies[$row['code']] = [
                        'title'  => $row['title'],
                        'value'  => 1,
                        'code'   => $row['code'],
                        'prefix' => $row['symbol_left'],
                        'suffix' => $row['symbol_right'],
                        'dec_pt' => $row['decimal_point'],
                        'sep'    => $row['thousands_point'],
                        'dec_len'=> $row['decimal_places'],
                        'pfxneg' => '-',
                        'sfxneg' => ''];
                }
                setModuleCache('phreebooks', 'currency', 'iso', $currencies);
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."journal_main CHANGE currency currency CHAR(3) NOT NULL DEFAULT '$curDefault'");
                dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."inventory_prices CHANGE currency currency CHAR(3) NOT NULL DEFAULT '$curDefault'");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."currencies");
                // set the new totals methods
                $tMeths = ['balance','balanceBeg','balanceEnd','debitcredit','discount','discountChk','shipping','subtotal','subtotalChk','tax_item','total'];
                bizAutoLoad(BIZUNO_LIB."controllers/bizuno/settings.php", 'bizunoSettings');
                $installer = new bizunoSettings();
                $layout = [];
                foreach ($tMeths as $meth) { $installer->methodInstall($layout, ['module'=>'phreebooks', 'path'=>'totals', 'method'=>$meth], false); }
                // install the inventory prices methods
                $iMeths = ['byContact','bySKU','quantity'];
                foreach ($iMeths as $meth) { $installer->methodInstall($layout, ['module'=>'inventory', 'path'=>'prices', 'method'=>$meth], false); }
                break;
            case 17: // clean up some files
                $io->folderDelete($this->myFolder."data/temp/");
                // convert inventory master stock
                $results = dbGetMulti(BIZUNO_DB_PREFIX."inventory_ms_list");
                if (!$results || sizeof($results)==0) { }
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."inventory_ms_list");
                // inventory vendors
                if (dbTableExists(BIZUNO_DB_PREFIX."inventory_purchase_details")) {
                    $results = dbGetMulti(BIZUNO_DB_PREFIX."inventory_purchase_details", '', 'sku');
                    if ($results && sizeof($results) > 0) { }
                    dbGetResult("DROP TABLE ".BIZUNO_DB_PREFIX."inventory_purchase_details");
                }
                if (dbTableExists(BIZUNO_DB_PREFIX."phreepos_tills"))  { // phreePOS if not in use
                    $results = dbGetMulti(BIZUNO_DB_PREFIX."phreepos_other_trans");
                    if (!$results || sizeof($results)==0) { dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."phreepos_other_trans"); }
                    $results = dbGetMulti(BIZUNO_DB_PREFIX."phreepos_tills");
                    if (!$results || sizeof($results)==0) { dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."phreepos_tills"); }
                }
                // drop some no longer needed tables
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."phreehelp");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."translator");
                break;
            case 18:
                // reset the rep_id's in the journal_main table to user ID's
                $users = dbGetMulti(BIZUNO_DB_PREFIX.'users');
                foreach ($users as $row) {
                    $settings = json_decode($row['settings'], true);
                    if (isset($settings['contact_id']) && $settings['contact_id']) {
                        dbWrite(BIZUNO_DB_PREFIX.'journal_main', array('rep_id'=>$row['admin_id']), 'update', "rep_id={$settings['contact_id']}");
                    }
                }
//                dbGetResult("UPDATE ".BIZUNO_DB_PREFIX."phreeform SET doc_data='' WHERE mime_type='dir'");
                if (dbTableExists(BIZUNO_DB_PREFIX."shipping_status")) {
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."extShipping");
                    dbGetResult("RENAME TABLE ".BIZUNO_DB_PREFIX."shipping_status TO ".BIZUNO_DB_PREFIX."extShipping");
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."extShipping", "config_key LIKE 'SHIPPING_%");
                    foreach ($result as $value) dbWrite(BIZUNO_DB_PREFIX."extShipping", array('config_key'=>str_replace($value, 'SHIPPING_', 'EXTSHIPPING')), 'update', "config_key='$value'");
                    rename($this->myFolder."data/shipping", $this->myFolder."data/extShipping");
                }
                // convert tabs table to settings by module
                $result = dbGetMulti(BIZUNO_DB_PREFIX.'xtra_tabs');
                $output = array();
                foreach ($result as $row) {
                    $output[$row['module_id']][$row['id']] = array('table_id'=>$row['module_id'], 'title'=>$row['tab_name'], 'sort_order'=>$row['sort_order']);
                }
                foreach ($output as $mID => $values) {
                    setModuleCache($mID, 'tabs', false, $values);
                }
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."xtra_tabs");
                break;
            case 19:
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."contacts_log WHERE contact_id = 0"); // remove orphaned contacts
                // moved from Work Order for Dreamweaver
                if (dbTableExists(BIZUNO_DB_PREFIX."wo_journal_item")) {
                    // merge the wo_journal_item table into srvBuilder_journal
                    $result = dbGetMulti(BIZUNO_DB_PREFIX."wo_journal_item");
                    $output = array();
                    foreach ($result as $row) {
                        $output[$row['ref_id']][$row['step']] = array(
                            'task_id'   => $row['task_id'],
                            'mfg'       => $row['mfg'],
                            'mfg_id'    => $row['mfg_id'],
                            'mfg_date'  => $row['mfg_date'],
                            'qa'        => $row['qa'],
                            'qa_id'     => $row['qa_id'],
                            'qa_date'   => $row['qa_date'],
                            'data_entry'=> $row['data_entry'],
                            'data_value'=> $row['data_value'],
                            'admin_id'  => $row['admin_id'],
                            'complete'  => $row['complete'],
                            'erp_entry' => dbGetValue(BIZUNO_DB_PREFIX."srvBuilder_tasks", 'erp_entry', "id=".$row['task_id']),
                        );
                    }
                    foreach ($output as $id => $value) {
                        dbWrite(BIZUNO_DB_PREFIX."srvBuilder_journal", array('steps'=>json_encode($value)), 'update', "id=$id");
                    }
                    dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."wo_journal_item");
                }
                break;
            case 20:
                if (dbFieldExists(BIZUNO_DB_PREFIX."current_status", 'next_wo_num')) dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."current_status CHANGE next_wo_num next_wo_num VARCHAR(16) NOT NULL DEFAULT 'WO-0001' COMMENT 'label:Next Build Order Number'");
                if (dbFieldExists(BIZUNO_DB_PREFIX."current_status", 'next_rma_num'))dbGetResult("ALTER TABLE ".BIZUNO_DB_PREFIX."current_status CHANGE next_rma_num next_return_num VARCHAR(16) NOT NULL DEFAULT 'RTN00001' COMMENT 'label:Next Return Number'");

                $keys = array('MODULE_RECEIVING_STATUS','MODULE_PHREECRM_STATUS','extShipping_fedex_v7','payment_paypal_nvp','PHREEHELP_FORCE_RELOAD',
                    'CURRENT_ACCOUNTING_PERIOD','CURRENT_ACCOUNTING_PERIOD_START','CURRENT_ACCOUNTING_PERIOD_END',
                    'ENCRYPTION_VALUE','DEFAULT_CURRENCY'); // Delete DEFAULT_CURRENCY last!
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key IN ('".implode("','",$keys)."')");
                dbGetResult("DELETE FROM ".BIZUNO_DB_PREFIX."config_phreebooks WHERE config_key LIKE 'MODULE_%'");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."help");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."config_phreebooks");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."phreeform_phreebooks");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."users_phreebooks");
                dbGetResult("DROP TABLE IF EXISTS ".BIZUNO_DB_PREFIX."inventory_fields");
                msgAdd("log file has been written!",'success');
                break;
            case 21:
                // At every upgrade, run the comments repair tool to fix changes to the view structure
                bizAutoLoad(BIZUNO_LIB."controllers/bizuno/tools.php", 'bizunoTools');
                $ctl = new bizunoTools();
                $ctl->repairComments(false);
                break;
        }
        return $error ? false : true;
    } // EOF Function upgrade

    public function justReports()
    {
        bizAutoLoad(BIZUNO_LIB."controllers/phreeform/functions.php", 'phreeformImport', 'function');
        $path = $this->myFolder.'/data/phreeform';
        $result = dbGetMulti(BIZUNO_DB_PREFIX."phreeform");
            foreach ($result as $row) {
            msgDebug("\n looking for report: $path/pf_".$row['id']);
            $report = @file_get_contents("$path/pf_".$row['id']);
            if ($report) {
                $tmp = object_to_xml(bizuno_simpleXML($report), false, '', 0, true); // removes any CDATA containers
                $contents = $this->convertReports($tmp);
                dbWrite(BIZUNO_DB_PREFIX."phreeform", array('doc_data'=>$contents), 'update', 'id='.$row['id']);
                msgDebug(" ... converted!");
            } else {
                msgDebug(" ... no file found!");
            }
            // put reports into proper format
            if ($row['mime_type'] == 'rpt') {
                $group = explode(':', $row['group_id']);
                dbWrite(BIZUNO_DB_PREFIX."phreeform", array('group_id'=>$group[0].':rpt'), 'update', 'id='.$row['id']);
                msgDebug("\n    Updated report id: ".$row['id']);
            } elseif ($row['mime_type']=='fr') {
                dbWrite(BIZUNO_DB_PREFIX."phreeform", array('group_id'=>$row['group_id'].':rpt'), 'update', 'id='.$row['id']);
            }
            if ($row['mime_type']=='0'  || $row['mime_type']=='fr' || $row['mime_type']=='ff') {
                dbWrite(BIZUNO_DB_PREFIX."phreeform", array('mime_type'=>'dir'), 'update', 'id='.$row['id']);
            }
        }
        msgAdd("completed converting reports!", 'success');
    }

    private function convertReports($xmlData)
    {
        // some table name changes
        $xmlData = str_replace('accounting_periods',       'journal_periods',    $xmlData);
        $xmlData = str_replace('chart_of_accounts_history','journal_history',    $xmlData);
        // some field names
        $xmlData = str_replace('audit_log.admin_name',     'audit_log.user_id',  $xmlData);
        $xmlData = str_replace('audit_log.action_date',    'audit_log.date',     $xmlData);
        $xmlData = str_replace('audit_log.action',         'audit_log.log_entry',$xmlData);
        $xmlData = str_replace('audit_log.reference_id',   'audit_log.user_id',  $xmlData);
        $xmlData = str_replace('audit_log.amount',         'audit_log.user_id',  $xmlData);

        $xmlData = str_replace('users.admin_name',         'users.title',     $xmlData);

        $xmlData = str_replace('address_book.city_town',     'address_book.city',   $xmlData);
        $xmlData = str_replace('address_book.state_province','address_book.state',  $xmlData);
        $xmlData = str_replace('address_book.country_code',  'address_book.country',$xmlData);

        $xmlData = str_replace('journal_main.shipper_code',  'journal_main.method_code',$xmlData);

        $xmlData = str_replace('account_inventory_wage', 'gl_inv',     $xmlData);
        $xmlData = str_replace('account_sales_income',   'gl_sales',   $xmlData);
        $xmlData = str_replace('account_cost_of_sales',  'gl_cogs',    $xmlData);
        $xmlData = str_replace('minimum_stock_level',    'qty_min',    $xmlData);
        $xmlData = str_replace('quantity_on_hand',       'qty_stock',  $xmlData);
        $xmlData = str_replace('reorder_quantity',       'qty_restock',$xmlData);
        $xmlData = str_replace('quantity_on_order',      'qty_po',     $xmlData);
        $xmlData = str_replace('quantity_on_sales_order','qty_so',     $xmlData);
        $xmlData = str_replace('quantity_on_allocation', 'qty_alloc',  $xmlData);

        $xmlData = str_replace('purchase_invoice_id','invoice_num',   $xmlData);
        $xmlData = str_replace('bill_acct_id',       'contact_id_b',  $xmlData);
        $xmlData = str_replace('bill_address_id',    'address_id_b',  $xmlData);
        $xmlData = str_replace('bill_primary_name',  'primary_name_b',$xmlData);
        $xmlData = str_replace('bill_contact',       'contact_b',     $xmlData);
        $xmlData = str_replace('bill_address1',      'address1_b',    $xmlData);
        $xmlData = str_replace('bill_address2',      'address2_b',    $xmlData);
        $xmlData = str_replace('bill_city_town',     'city_b',        $xmlData);
        $xmlData = str_replace('bill_state_province','state_b',       $xmlData);
        $xmlData = str_replace('bill_country_code',  'country_b',     $xmlData);
        $xmlData = str_replace('bill_postal_code',   'postal_code_b', $xmlData);
        $xmlData = str_replace('bill_telephone1',    'telephone1_b',  $xmlData);
        $xmlData = str_replace('bill_email',         'email_b',       $xmlData);
        $xmlData = str_replace('ship_acct_id',       'contact_id_s',  $xmlData);
        $xmlData = str_replace('ship_address_id',    'address_id_s',  $xmlData);
        $xmlData = str_replace('ship_primary_name',  'primary_name_s',$xmlData);
        $xmlData = str_replace('ship_contact',       'contact_s',     $xmlData);
        $xmlData = str_replace('ship_address1',      'address1_s',    $xmlData);
        $xmlData = str_replace('ship_address2',      'address2_s',    $xmlData);
        $xmlData = str_replace('ship_city_town',     'city_s',        $xmlData);
        $xmlData = str_replace('ship_state_province','state_s',       $xmlData);
        $xmlData = str_replace('ship_country_code',  'country_s',     $xmlData);
        $xmlData = str_replace('ship_postal_code',   'postal_code_s', $xmlData);
        $xmlData = str_replace('ship_telephone1',    'telephone1_s',  $xmlData);
        $xmlData = str_replace('ship_email',         'email_s',       $xmlData);

//        $xmlData = str_replace('<page>', '',  $xmlData); // remove the page tags to move all contents up one level
//        $xmlData = str_replace('</page>', '', $xmlData); // THE CODE IS CURRENTLY WRITTEN TO SUPPORT PAGE LEVEL
        $xmlData = str_replace('columnwidth>', 'width>', $xmlData);
        $xmlData = str_replace('columnbreak>', 'break>', $xmlData);
        // change first description to desctemp so it doesn't get messed up
        $xmlData = preg_replace('/description/','descTemp',      $xmlData, 2);
        $xmlData = str_replace('description>',  'title>',        $xmlData);
        $xmlData = str_replace('descTemp>',     'description>',  $xmlData);
        $xmlData = str_replace('min_val>',      'min>',          $xmlData);
        $xmlData = str_replace('max_val>',      'max>',          $xmlData);
        $xmlData = str_replace('null-dlr',      'null_dlr',      $xmlData);
        $xmlData = str_replace('<min>chk</min>','<min>pmt</min>',$xmlData);
        $xmlData = str_replace('<min>poo</min>','<min>itm</min>',$xmlData);
        $xmlData = str_replace('<min>por</min>','<min>itm</min>',$xmlData);
        $xmlData = str_replace('<min>soo</min>','<min>itm</min>',$xmlData);
        $xmlData = str_replace('<min>sos</min>','<min>itm</min>',$xmlData);
        $xmlData = str_replace('<min>cm</min>', '<min>m</min>',  $xmlData);
        $xmlData = str_replace('<min>vm</min>', '<min>m</min>',  $xmlData);
        // change block processing to separators
        $xmlData = str_replace('<processing>sp</processing>',        '<separator>sp</separator>',        $xmlData);
        $xmlData = str_replace('<processing>2sp</processing>',       '<separator>2sp</separator>',       $xmlData);
        $xmlData = str_replace('<processing>comma</processing>',     '<separator>comma</separator>',     $xmlData);
        $xmlData = str_replace('<processing>com-sp</processing>',    '<separator>com-sp</separator>',    $xmlData);
        $xmlData = str_replace('<processing>nl</processing>',        '<separator>nl</separator>',        $xmlData);
        $xmlData = str_replace('<processing>semi-sp</processing>',   '<separator>semi-sp</separator>',   $xmlData);
        $xmlData = str_replace('<processing>del-nl</processing>',    '<separator>del-nl</separator>',    $xmlData);
        $xmlData = str_replace('<processing>def_cur</processing>',   '<processing>currency</processing>',$xmlData);
        $xmlData = str_replace('<processing>null_dcur</processing>', '<processing>cur_null</processing>',$xmlData);
        $xmlData = str_replace('<processing>posted_cur</processing>','<processing>currency</processing>',$xmlData);
        $xmlData = str_replace('<processing>null_pcur</processing>', '<processing>cur_null</processing>',$xmlData);
        $xmlData = str_replace('<processing>null-dlr</processing>',  '<processing>cur_null</processing>',$xmlData);
        $xmlData = str_replace('<processing>dlr</processing>',       '<processing>currency</processing>',$xmlData);
        $xmlData = str_replace('<processing>euro</processing>',      '<processing>currency</processing>',$xmlData);
        // convert company constants to registry values
        $xmlData = str_replace('COMPANY_ID',         'id',          $xmlData);
        $xmlData = str_replace('COMPANY_NAME',       'primary_name',$xmlData);
        $xmlData = str_replace('AR_CONTACT_NAME',    'contact',     $xmlData);
        $xmlData = str_replace('AP_CONTACT_NAME',    'contact_ap',  $xmlData);
        $xmlData = str_replace('COMPANY_ADDRESS1',   'address1',    $xmlData);
        $xmlData = str_replace('COMPANY_ADDRESS2',   'address2',    $xmlData);
        $xmlData = str_replace('COMPANY_CITY_TOWN',  'city',        $xmlData);
        $xmlData = str_replace('COMPANY_ZONE',       'state',       $xmlData);
        $xmlData = str_replace('COMPANY_POSTAL_CODE','postal_code', $xmlData);
        $xmlData = str_replace('COMPANY_COUNTRY',    'country',     $xmlData);
        $xmlData = str_replace('COMPANY_TELEPHONE1', 'telephone1',  $xmlData);
        $xmlData = str_replace('COMPANY_TELEPHONE2', 'telephone2',  $xmlData);
        $xmlData = str_replace('COMPANY_FAX',        'telephone3',  $xmlData);
        $xmlData = str_replace('COMPANY_EMAIL',      'email',       $xmlData);
        $xmlData = str_replace('COMPANY_WEBSITE',    'website',     $xmlData);
        $xmlData = str_replace('TAX_ID',             'tax_id',      $xmlData);
        // turn the critChoices numeric to assoc
        $CritChoices = [
            0  => '2:ALL:RANGE:EQUAL',
            1  => '0:YES:NO',
            2  => '0:ALL:YES:NO',
            3  => '0:ALL:ACTIVE:INACTIVE',
            4  => '0:ALL:PRINTED:UNPRINTED',
            6  => '1:EQUAL',
            7  => '2:RANGE',
            8  => '1:NOT_EQUAL',
            9  => '1:IN_LIST',
            10 => '1:LESS_THAN',
            11 => '1:GREATER_THAN',
            ];
        foreach ($CritChoices as $key => $value) { $xmlData = str_replace("<type>$value</type>", "<type>$key</type>", $xmlData); }
        // alter structure for reports
        if (strpos($xmlData, ">rpt<") !== false) {
            $report = phreeFormXML2Obj($xmlData);
            if (isset($report->page->heading)){ $report->heading= $report->page->heading;unset($report->page->heading);}
            if (isset($report->page->title1)) { $report->title1 = $report->page->title1; unset($report->page->title1); }
            if (isset($report->page->title2)) { $report->title2 = $report->page->title2; unset($report->page->title2); }
            if (isset($report->page->filter)) { $report->filter = $report->page->filter; unset($report->page->filter); }
            if (isset($report->page->data))   { $report->data   = $report->page->data;   unset($report->page->data);   }
            $xmlData = object_to_xml($report, false, '', 0, true);
        }
        // alter structure for forms
        if (strpos($xmlData, ">frm<") !== false) {
            $report = phreeFormXML2Obj($xmlData);
            if (is_array($report->title)) {
                $report->description = $report->title[1];
                $report->title       = $report->title[0];
            }
            if (isset($report->fieldlist)) {
                foreach ($report->fieldlist as $key => $value) {
                    if (!isset($report->fieldlist[$key]->settings)) { $report->fieldlist[$key]->settings = new stdClass(); }
                    if (isset($value->hfont))       { $report->fieldlist[$key]->settings->hfont  = $value->hfont;       unset($report->fieldlist[$key]->hfont); }
                    if (isset($value->hsize))       { $report->fieldlist[$key]->settings->hsize  = $value->hsize;       unset($report->fieldlist[$key]->hsize); }
                    if (isset($value->halign))      { $report->fieldlist[$key]->settings->halign = $value->halign;      unset($report->fieldlist[$key]->halign); }
                    if (isset($value->hcolor))      { $report->fieldlist[$key]->settings->hcolor = $value->hcolor;      unset($report->fieldlist[$key]->hcolor); }
                    if (isset($value->hbordershow)) { $report->fieldlist[$key]->settings->hbshow = $value->hbordershow; unset($report->fieldlist[$key]->hbordershow); }
                    if (isset($value->hbordersize)) { $report->fieldlist[$key]->settings->hbsize = $value->hbordersize; unset($report->fieldlist[$key]->hbordersize); }
                    if (isset($value->hbordercolor)){ $report->fieldlist[$key]->settings->hbcolor= $value->hbordercolor;unset($report->fieldlist[$key]->hbordercolor); }
                    if (isset($value->hfillcolor))  { $report->fieldlist[$key]->settings->hfcolor= $value->hfillcolor;  unset($report->fieldlist[$key]->hfillcolor); }
                    if (isset($value->filename))    { $report->fieldlist[$key]->settings->filename=$value->filename;    unset($report->fieldlist[$key]->filename); }
                    if (isset($value->font)) {
                        if (is_array($value->font)) { $value->font = $value->font[0]; } // fix bug in prior reports where this is an array
                        $report->fieldlist[$key]->settings->font = $value->font;
                        unset($report->fieldlist[$key]->font);
                    }
                    if (isset($value->size)) {
                        if (is_array($value->size)) { $value->size = $value->size[0]; }
                        $report->fieldlist[$key]->settings->size = $value->size;
                        unset($report->fieldlist[$key]->size);
                    }
                    if (isset($value->align)) {
                        if (is_array($value->align)) { $value->align= $value->align[0]; }
                        $report->fieldlist[$key]->settings->align = $value->align;
                        unset($report->fieldlist[$key]->align);
                    }
                    if (isset($value->color)) {
                        if (is_array($value->color)) { $value->color= $value->color[0]; }
                        $report->fieldlist[$key]->settings->color = $value->color;
                        unset($report->fieldlist[$key]->color);
                    }
                    if (isset($value->bordershow))  { $report->fieldlist[$key]->settings->bshow   = $value->bordershow;  unset($report->fieldlist[$key]->bordershow); }
                    if (isset($value->bordersize))  { $report->fieldlist[$key]->settings->bsize   = $value->bordersize;  unset($report->fieldlist[$key]->bordersize); }
                    if (isset($value->bordercolor)) { $report->fieldlist[$key]->settings->bcolor  = $value->bordercolor; unset($report->fieldlist[$key]->bordercolor); }
                    if (isset($value->fillcolor))   { $report->fieldlist[$key]->settings->fcolor  = $value->fillcolor;   unset($report->fieldlist[$key]->fillcolor); }
                    if (isset($value->linetype))    { $report->fieldlist[$key]->settings->linetype= $value->linetype;    unset($report->fieldlist[$key]->linetype); }
                    if (isset($value->length))      { $report->fieldlist[$key]->settings->length  = $value->length;      unset($report->fieldlist[$key]->length); }
                    if (isset($value->text))        { $report->fieldlist[$key]->settings->text    = $value->text;        unset($report->fieldlist[$key]->text); }
                    if (isset($value->display))     { $report->fieldlist[$key]->settings->display = $value->display;     unset($report->fieldlist[$key]->display); }
                    if (isset($value->boxfield))    {
                        if (!is_array($value->boxfield)) { $value->boxfield = array($value->boxfield); }
                        if (in_array($value->type, array('Data', 'CDta'))) {
                            if (isset($value->boxfield[0]->fieldname))  $report->fieldlist[$key]->settings->fieldname  = $value->boxfield[0]->fieldname;
                            if (isset($value->boxfield[0]->processing)) $report->fieldlist[$key]->settings->processing = $value->boxfield[0]->processing;
                        } elseif (in_array($value->type, array('TBlk', 'CBlk', 'Ttl'))) {
                            foreach ($value->boxfield as $bkey => $bvalue) {
                                if (isset($bvalue->font))  unset($value->boxfield[$bkey]->font);
                                if (isset($bvalue->size))  unset($value->boxfield[$bkey]->size);
                                if (isset($bvalue->align)) unset($value->boxfield[$bkey]->align);
                                if (isset($bvalue->color)) unset($value->boxfield[$bkey]->color);
                            }
                            $report->fieldlist[$key]->settings->boxfield = $value->boxfield;
                        } elseif (in_array($value->type, array('Tbl'))) {
                            $report->fieldlist[$key]->settings->boxfield = $value->boxfield;
                            if (isset($report->fieldlist[$key]->settings->font))  unset($report->fieldlist[$key]->settings->font);
                            if (isset($report->fieldlist[$key]->settings->size))  unset($report->fieldlist[$key]->settings->size);
                            if (isset($report->fieldlist[$key]->settings->align)) unset($report->fieldlist[$key]->settings->align);
                            if (isset($report->fieldlist[$key]->settings->color)) unset($report->fieldlist[$key]->settings->color);
                        }
                        unset($report->fieldlist[$key]->boxfield);
                    }
                }
            }
            $xmlData = object_to_xml($report, false, '', 0, true);
        }
        return $xmlData;
    }

    /**
     * Grid to list files to restore
     * @param string $name - html element id of the grid
     * @return array $data - grid structure
     */
    private function dgConvert($name='dgConvert')
    {
        return ['id'=>$name, 'title'=>lang('files'),
            'attr'   => ['idField'=>'title', 'url'=>BIZUNO_AJAX."&bizRt=bizuno/backup/mgrRows&db=1"],
            'columns'=> [
                'action'=> ['order'=>1,'label'=>lang('action'),'events'=>['formatter'=>"function(value,row,index) { return {$name}Formatter(value,row,index); }"],
                    'actions' => ['start'=>['order'=>30,'icon'=>'import','events'=>['onClick'=>"if (confirm('".$this->lang['msg_restore_confirm']."')) { xfrClickFile('{$this->dirBackup}idTBD') }"]]]],
                'title' => ['order'=>10,'label'=>lang('filename'),'attr'=>['width'=>200,'align'=>'center','resizable'=>true]],
                'size'  => ['order'=>20,'label'=>lang('size'),    'attr'=>['width'=> 75,'align'=>'center','resizable'=>true]],
                'date'  => ['order'=>30,'label'=>lang('date'),    'attr'=>['width'=> 75,'align'=>'center','resizable'=>true]]]];
    }

    /**
     * Method to receive a file to upload into the backup folder for db restoration
     * @param array $layout - structure coming in
     * @return modified $layout
     */
    public function uploadConvert(&$layout)
    {
        global $io;
        $io->uploadSave('fldFile', $this->dirBackup);
        $layout = array_replace_recursive($layout, ['content'=>['action'=>'eval','actionData'=>"jqBiz('#dgConvert').datagrid('reload');"]]);
    }
}