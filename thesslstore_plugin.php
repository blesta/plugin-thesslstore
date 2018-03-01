<?php
/**
 * TheSSLStore plugin handler
 *
 */
class ThesslstorePlugin extends Plugin {

    /**
     * @var string The version of this plugin
     */
    private static $version = "1.2.0";
    /**
     * @var string The authors of this plugin
     */
    private static $authors = array(array('name' => "The SSL Store", 'url' => "https://www.thesslstore.com"));

    public function __construct() {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Language::loadLang("thesslstore_plugin", null, dirname(__FILE__) . DS . "language" . DS);
        require_once(COMPONENTDIR.'modules/thesslstore_module/api/thesslstoreApi.php');
    }

    /**
     * Returns the name of this plugin
     *
     * @return string The common name of this plugin
     */
    public function getName() {
        return Language::_("TheSSLStorePlugin.name", true);
    }

    /**
     * Returns the version of this plugin
     *
     * @return string The current version of this plugin
     */
    public function getVersion() {
        return self::$version;
    }

    /**
     * Returns the name and URL for the authors of this plugin
     *
     * @return array The name and URL of the authors of this plugin
     */
    public function getAuthors() {
        return self::$authors;
    }

    /**
     * Performs any necessary bootstraping actions
     *
     * @param int $plugin_id The ID of the plugin being installed
     */
    public function install($plugin_id) {

        Loader::loadModels($this, array('CronTasks'));
        $this->addCronTasks($this->getCronTasks());
        // Create Email group for expiration reminder email
        Loader::loadModels($this, array("Emails", "EmailGroups", "Languages", "Permissions"));
        $group = array(
                'action' => "Thesslstore.expiration_reminder",
                'type' => "client",
                'plugin_dir' => "thesslstore",
                'tags' => "{client.first_name},{package.name},{service.id},{servicelink},{days}"
        );
        // Add the custom group
        $group_id = $this->EmailGroups->add($group);
        // Fetch all currently-installed languages for this company, for which email templates should be created for
        $languages = $this->Languages->getAll(Configure::get("Blesta.company_id"));
        // Add the email template for each language
        foreach ($languages as $language)
        {
            $this->Emails->add(array(
            'email_group_id' => $group_id,
            'company_id' =>Configure::get("Blesta.company_id"),
            'lang' => $language->code,
            'from' => "no-reply@mydomain.com",
            'from_name' => "My Company",
            'subject' => "Certificate Expiration Reminder",
            'text' => "Dear {client.first_name},
            This is a reminder that your SSL Certificate ({package.name}) with the service ID#{service.id} ({servicelink}) will going to expire in {days} days. Renew now before it's too late...
            If you do not proceed with the same, your certificate will be expired.
            Thank you for using our services!",
            'html' => "<p>Dear {client.first_name},</p>
            <p>This is a reminder that your SSL Certificate ({package.name}) with the service ID#{service.id} ({servicelink}) will going to expire in {days} days. Renew now before it's too late...</p>
            <P>If you do not proceed with the same, your certificate will be expired.</P>
            <P>Thank you for using our services!</P>"
            ));
        }
    }

    /**
     * Performs any necessary cleanup actions
     *
     * @param int $plugin_id The ID of the plugin being uninstalled
     * @param boolean $last_instance True if $plugin_id is the last instance across all companies for this plugin, false otherwise
     */
    public function uninstall($plugin_id, $last_instance) {
        Loader::loadModels($this, array("Emails", "EmailGroups", "Languages"));
        // Fetch the email template created by this plugin
        $group = $this->EmailGroups->getByAction('Thesslstore.expiration_reminder');

        // Delete all emails templates belonging to this plugin's email group and company
        if ($group) {
            $this->Emails->deleteAll($group->id, Configure::get("Blesta.company_id"));
            if ($last_instance)
                $this->EmailGroups->delete($group->id);
        }


        if (!isset($this->Record)) {
            Loader::loadComponents($this, array('Record'));
        }
        Loader::loadModels(
            $this,
            array('CronTasks')
        );

        $cron_tasks = $this->getCronTasks();

        if ($last_instance) {
            // Remove the cron tasks
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks
                    ->getByKey($task['key'], $task['plugin_dir']);
                if ($cron_task) {
                    $this->CronTasks->delete($cron_task->id, $task['plugin_dir']);
                }
            }
        }

        // Remove individual cron task runs
        foreach ($cron_tasks as $task) {
            $cron_task_run = $this->CronTasks
                ->getTaskRunByKey($task['key'], $task['plugin_dir']);
            if ($cron_task_run) {
                $this->CronTasks->deleteTaskRun($cron_task_run->task_run_id);
            }
        }
    }

    public function cron($key)
    {
        if ($key == 'tss_order_sync') {
            $this->order_synchronization();
        }
        if ($key == 'tss_expiration_reminder') {
            $this->certificate_expiration_reminder();
        }
    }

    /**
     * Synchronization order data
     *
     */
   private function order_synchronization(){

        Loader::loadModels($this, array('Services','ModuleManager'));

        $company_id = Configure::get("Blesta.company_id");

        $today_date = strtotime("now");
        $today_date = $today_date * 1000; //convert into milliseconds
        $two_month_before_date = strtotime("-2 Months");
        $two_month_before_date = $two_month_before_date * 1000; //convert into milliseconds

        //get module row id
        $module_row_id = 0;
        $api_partner_code = '';
        $api_auth_token = '';
        $api_mode = '';
        $modules = $this->ModuleManager->getAll($company_id);
        foreach($modules as $module){
            $rows = $this->ModuleManager->getRows($module->id);
            foreach($rows as $row){
                if(isset($row->meta->thesslstore_reseller_name)) {
                    $module_row_id = $row->id;
                    $api_mode = $row->meta->api_mode;
                    if($api_mode == 'TEST'){
                        $api_partner_code = $row->meta->api_partner_code_test;
                        $api_auth_token = $row->meta->api_auth_token_test;
                    }
                    elseif($api_mode == 'LIVE'){
                        $api_partner_code = $row->meta->api_partner_code_live;
                        $api_auth_token = $row->meta->api_auth_token_live;
                    }
                    break 2;
                }
            }
        }

        $api = new thesslstoreApi($api_partner_code, $api_auth_token, $token = '', $tokenID = '', $tokenCode = '', $IsUsedForTokenSystem = false, $api_mode);

        $order_query_request = new order_query_request();
        $order_query_request->StartDate = "/Date($two_month_before_date)/";
        $order_query_request->EndDate = "/Date($today_date)/";

        $order_query_resp = $api->order_query($order_query_request);

       $sub_query_sql = "SELECT `service_id` FROM `service_fields` WHERE `key` = 'thesslstore_fqdn' GROUP BY `service_id`";

       $services = $this->Record->select()->from("services")
           ->leftJoin("service_fields", "services.id", "=", "service_fields.service_id", false)
           ->where("service_fields.key", "=", "thesslstore_order_id")
           ->where("services.status", "=", "active")
           ->where("services.id", "notin", array($sub_query_sql), false)
           ->fetchAll();

        foreach($services as $service){

            $fields = $this->serviceFieldsToObject($this->Services->get($service->id)->fields);
            if(isset($fields->thesslstore_order_id) && !empty($order_query_resp)){
                foreach($order_query_resp as $order){
                    if($order->TheSSLStoreOrderID == $fields->thesslstore_order_id){
                        //update renewal date
                        if(!empty($order->CertificateEndDateInUTC)){
                            //convert date format to match with blesta
                            $end_date = date("Y-m-d h:i:s", strtotime($order->CertificateEndDateInUTC));
                            $end_date = date('Y-m-d h:i:s',strtotime($end_date. '-30 days')); //set 30 days earlier renewal date
                            if($end_date != $service->date_renews){
                                $vars['date_renews'] = $end_date;
                                $this->Services->edit($service->id, $vars, $bypass_module = true);
                            }
                        }
                        //update domain name(fqdn)
                        if(!empty($order->CommonName)){
                            if(isset($fields->thesslstore_fqdn)){
                                if($fields->thesslstore_fqdn != $order->CommonName) {
                                    //update
                                    $this->Services->editField($service->id, array(
                                        'key' => "thesslstore_fqdn",
                                        'value' => $order->CommonName,
                                        'encrypted' => 0
                                    ));
                                }
                            }
                            else{
                                //add
                                $this->Services->addField($service->id, array(
                                    'key' => "thesslstore_fqdn",
                                    'value' => $order->CommonName,
                                    'encrypted' => 0
                                ));
                            }
                        }
                        break;
                    }
                }
            }

        }
    }

    /**
     * Certificate Expiration Reminder function
     *
     */
    private function certificate_expiration_reminder(){
        Loader::loadModels($this, array('Services','ModuleManager'));
        Loader::loadModels($this, array("Emails", "EmailGroups", "Languages"));
        Loader::loadModels($this, array("Clients", "Contacts", "Emails", "ModuleManager"));
        $services = $this->Services->getList();
        foreach($services as $service){
            $fields = $this->serviceFieldsToObject($service->fields);
            if(isset($fields->thesslstore_order_id)){
                $renewsDate=$service->date_renews;
                //Convert it into a timestamp.
                $renewsDate = strtotime($renewsDate);
                //Get the current timestamp.
                $todaysDate = time();
                //Calculate the difference.
                $difference = $renewsDate - $todaysDate;
                //Convert seconds into days.
                $days = floor($difference / (60*60*24) );
                if($days=='30')
                {
                    $WEBDIR=WEBDIR;
                    $client = Configure::get("Route.client");
                    $service_link = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '') . '://'.$_SERVER['HTTP_HOST'].$WEBDIR.$client.'/'.'services/manage/'.$service->id.'/';
                    // Fetch the client
                    $client = $this->Clients->get($service->client_id);
                    $tags = array('client' => $client, 'package' => $service->package, 'servicelink'=>$service_link, 'service' => $service, 'days' => $days);
                    $this->Emails->send("Thesslstore.expiration_reminder", Configure::get("Blesta.company_id"), "en_us", $service->client_email, $tags, null, null, null, array('to_client_id' =>$service->client_id_code));
                }
            }
        }
    }


    /**
     * Attempts to add new cron tasks for this plugin
     *
     * @param array $tasks A list of cron tasks to add
     */
    private function addCronTasks(array $tasks)
    {
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);

            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey(
                    $task['key'],
                    $task['plugin_dir']
                );
                if ($cron_task) {
                    $task_id = $cron_task->id;
                }
            }

            if ($task_id) {
                $task_vars = array('enabled' => $task['enabled']);
                if ($task['type'] === "time") {
                    $task_vars['time'] = $task['type_value'];
                } else {
                    $task_vars['interval'] = $task['type_value'];
                }

                $this->CronTasks->addTaskRun($task_id, $task_vars);
            }
        }
    }

    /**
     * Retrieves cron tasks available to this plugin along with their default values
     *
     * @return array A list of cron tasks
     */
    private function getCronTasks()
    {
        return array(
            // Cron task to check for incoming email tickets
            array(
                'key' => 'tss_order_sync',
                'plugin_dir' => 'thesslstore',
                'name' => Language::_(
                    'TheSSLStorePlugin.getCronTasks.tss_order_sync_name',
                    true
                ),
                'description' => Language::_(
                    'TheSSLStorePlugin.getCronTasks.tss_order_sync_desc',
                    true
                ),
                'type' => 'time',
                'type_value' =>'00:00:00' ,
                'enabled' => 1
            ),
            array(
                'key' => 'tss_expiration_reminder',
                'plugin_dir' => 'thesslstore',
                'name' => Language::_(
                    'TheSSLStorePlugin.getCronTasks.tss_expiration_reminder_name',
                    true
                ),
                'description' => Language::_(
                    'TheSSLStorePlugin.getCronTasks.tss_expiration_reminder_desc',
                    true
                ),
                'type' => 'time',
                'type_value' => '00:00:00',
                'enabled' => 1
            )
        );
    }
    /**
     * Converts numerically indexed service field arrays into an object with member variables
     *
     * @param array $fields A numerically indexed array of stdClass objects containing key and value member variables, or an array containing 'key' and 'value' indexes
     * @return stdClass A stdClass objects with member variables
     */
    private function serviceFieldsToObject(array $fields) {
        $data = new stdClass();
        foreach ($fields as $field) {
            if (is_array($field))
                $data->{$field['key']} = $field['value'];
            else
                $data->{$field->key} = $field->value;
        }

        return $data;
    }
}