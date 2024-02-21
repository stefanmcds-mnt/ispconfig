<?php

/**
 * ISPConfig
 * a class to connect via remoting client to ISPConfig server
 *
 *
 *
 * @author STEF@N MCDS S.a.s. Alfonso SPERA
 * created:  01-01-2018
 * updated:  14-04-2021
 * version:  2
 */

namespace ISPConfig;

use SoapClient;
use Exception;

class ISPConfig
{
    /*
     * @var array response
     */
    protected $resp;
    /*
     * @var Last Exception function
     */
    protected $lastException;
    /**
     * @var int $client
     */
    protected $soapClient;
    /*
     * @var int $sessioID
     */
    protected $sessionID;
    /*
     * @var array function list on the server
     */
    protected $functionList;
    /*
     * @var response from makeCall method
     */
    protected $responseCall;

    /**
     * Create a new istance of ISPConfig
     * 
     * [
     *  'username' => ISPConfig remote username,
     *  'password' => ISPConfig remote username password,
     *  'soap_location' => ISPConfig URL soap location es 'https://xxx.xxx.xxx:8080/remote/index.php',
     *  'soap_uri' => ISPConfig URL soap uri es 'https://xxx.xxx.xxx:8080/remote/',
     *  'curl_uri' => ISPConfig URL curl uri es 'https://xxx.xxxx.xxx:8080/remote/json.php',
     *  'stream_context' => stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
     * ];
     * 
     * @param array $config
     */
    public function __construct(
        protected ?array $config = null
    ) {
        if (null === $this->config) {
            throw new Exception('Failed: config ise required');
            die && exit;
        }
    }

    /**
     * Get protected @var
     * @param string $type
     * @return @var
     */
    public function getVar(?string $type)
    {
        return $this->$type;
    }

    /**
     * Execute private method
     * @param string $method
     * @param array|null $data
     * @return response protected method
     */
    public function callMethod(?string $method = null, ?array $data = null)
    {
        return $this->$method($data);
    }

    /**
     * Get Values from multiminesional array
     */
    private function array_values_recursive(?array $arr)
    {
        $return = [];
        foreach ($arr as $val)
            if (is_array($val)) {
                $this->array_values_recursive($val);
            } else {
                $return[] = $val;
            }
        return $return;
    }

    /**
     * Call the remote SOAP
     * Will pass from the second params and any after directly to the remote call
     *
     * @param string $function is the method to invoke
     * more other @param with no limit arguments
     *
     * @return mixed The result of the remote function or false on failure.
     * If an exception occurs false is returned and the exception can queried by getLastException.
     *
     * created: 01-01-2018
     * updated: 14-04-2021
     */
    private function makeCall()
    {
        try {
            if ($this->connect()) {
                if ($this->login()) {
                    if (func_num_args()) {
                        $param[] = $this->sessionID;
                        // if passed one aguments
                        // the method to invoke is within args['method']
                        $args = func_get_args();
                        $function = $args[0];
                        array_shift($args);
                        //$param = array_merge_recursive($param, array_values($args));
                        foreach ($args as $a) {
                            if (is_array($a)) {
                                foreach ($a as $v) {
                                    $param[] = $v;
                                }
                            } else {
                                $param[] = $a;
                            }
                        }
                        $this->responseCall = call_user_func_array([$this->soapClient, $function], $param);
                    } else {
                        $this->responseCall = 'NO ARGS';
                    }
                } else {
                    $this->responseCall = 'NO LOGIN';
                }
                $this->logout($this->sessionID);
            } else {
                $this->responseCall = 'NO CONNECT';
            }
        } catch (Exception $exc) {
            $this->lastException = $exc;
            $this->responseCall = $this->lastException->getMessage();
        }
        return $this->responseCall;
    }

    /**
     * CURL Connection
     *
     * @param string $method to be call
     * @param array $params
     * @return array json decoded $response
     *
     * Example :
     * $result = makeCallCURL('login', array('username' => $remote_user, 'password' => $remote_pass, 'client_login' => false));
     */
    private function makeCallCURL(?string $method, ?array $params)
    {
        if (!is_array($params)) {
            $this->responseCall = false;
            return $this->responseCall;
        }
        $json = json_encode($params);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, 1);
        if ($params) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }
        // needed for self-signed cert
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        // end of needed for self-signed cert
        curl_setopt($curl, CURLOPT_URL, $this->config['curl_uri'] . '?' . $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response, true);
    }

    /**
     * Calls the remote SOAP call
     * Will pass from the second params and any after directly to the remote call
     *
     * @param string $function <p>The function name to call</p>
     * @param mixed $args <p>Second remote param</p>
     * @return mixed The result of the remote function or false on failure.
     * If an exception occurs false is returned and the exception can queried by getLastException.
     */
    private function makeCallSOAP(?string $function, mixed $args = null)
    {
        //if (in_array($function, $this->functionList)) {
        try {
            if ($this->connect()) {
                if ($this->login()) {
                    $a[] = $this->sessionID;
                    if ($args !== null) {
                        foreach ($args as $k => $t) {
                            $a[] = $t;
                        }
                    }
                    $this->responseCall = call_user_func_array([$this->soapClient, $function], $a);
                } else {
                    $this->responseCall = 'NO LOGIN';
                }
                $this->logout($this->sessionID);
            } else {
                $this->responseCall = 'NO CONNECT';
            }
        } catch (Exception $exc) {
            $this->lastException = $exc;
            $this->responseCall = $this->lastException->getMessage();
        }
        //} else {
        //$this->responseCall = false;
        //}
        return $this->responseCall;
    }

    /**
     * Connect to SOAP ISP Config server
     * @return boolean
     */
    private function connect()
    {
        $this->soapClient = new SoapClient(null, [
            'location' => $this->config['soap_location'],
            'uri' => $this->config['soap_uri'],
            'trace' => 1,
            'exceptions' => 1,
            'stream_context' => $this->config['stream_context']
        ]);
        if ($this->soapClient) {
            return true;
        } else {
            throw new Exception('Connect failed: ' . ($this->lastException ? $this->lastException->getMessage() : ''));
            return false;
        }
    }

    /**
     * Login to Server
     *
     * @return boolean true OR false
     */
    private function login()
    {
        if (!empty($this->soapClient)) {
            if ($response = $this->soapClient->login($this->config['username'], $this->config['password'])) {
                $this->sessionID = $response;
                $return = true;
            } else {
                $this->sessionID = null;
                $return = false;
                throw new Exception('Login failed: ' . ($this->lastException ? $this->lastException->getMessage() : ''));
            }
        } else {
            if ($response = $this->makeCall('login', ['username' => $this->config['username'], 'password' => $this->config['password']])) {
                $this->sessionID = $response['response'];
                $return = true;
            } else {
                $this->sessionID = null;
                $return = false;
                throw new Exception('Login failed: ' . ($this->lastException ? $this->lastException->getMessage() : ''));
            }
        }
        return $return;
    }

    /**
     * Logout from server
     * @return boolean
     */
    private function logout()
    {
        if ($this->sessionID) {
            return $this->soapClient->logout($this->sessionID);
        }
        return false;
    }

    /**
     * getFunction
     * Shows all available remote API functions
     *
     * @param int $session_id
     * @return array function list
     */
    private function getFunction()
    {
        if ($this->connect() && $this->login()) {
            $this->functionList = $this->soapClient->get_function_list($this->sessionID);
            $this->logout();
        }
    }

    /**
     * Method for Admin
     *
     * - 'method' => 'update_record_permissions' set record permissions in any table permissions ['sys_userid'>='riud', 'sys_groupid'=>'riud', 'sys_perm_user' => 'riud', 'sys_perm_group'=>'riud']
     *
     * @param array $param
     * @return response
     *
     * Example:
     * $params = ['method' => 'update_record_permissions', 'params' => [$tablename, $index_field, $index_value, $permissions]]
     */
    private function adminsFunctions(?array $param)
    {
        $params = (isset($param['params'])) ? $param['params'] : null;
        return $this->makeCall($param['method'], $params);
    }

    /**
     * Method for APS to be implemented
     *
     *
     * @param array $param
     * @return type
     */
    private function apsFunctions(?array $param)
    {
        $params = (isset($param['params'])) ? $param['params'] : null;
        return $this->makeCall($param['method'], $params);
    }

    /**
     * Add a New Client
     *
     * @param array $params
     * @return array $response the ID of the newly added Client or false.
     *
     * Method for Client
     *
     * $method = 'client_add' Returns the ID of the newly added Client.
     * $method = 'client_change_password' Returns '1' if password has been changed.
     * $method = 'client_delete' Returns the number of deleted records.
     * $method = 'client_delete_everything' Delete everything of client Returns the number of deleted records.
     * $method = 'client_get' Retrieves information about a client.
     * $method = 'client_get_all' Returns All client_id's from database
     * $method = 'client_get_by_customer_no' Return all field of client from his cutomer_no
     * $method = 'client_get_by_username' Returns client information of the user specified by his or her name.
     * $method = 'client_get_emailcontact' Returns the contact details to send a email like email address, name, etc.
     * $method = 'client_get_groupid' Return groupid client.
     * $method = 'client_get_id' Returns the client ID of the user with the entered system user ID.
     * $method = 'client_login_get' Return the login of authenticated client
     * $method = 'client_template_additional_add' Add a template to client
     * $method = 'client_template_additional_delete' Delete a templete to client
     * $method = 'client_template_additional_get' Return all fields of template client
     * $method = 'client_templates_get_all' Return all tamplates available to client
     * $method = 'client_update' Returns the number of affected rows .
     *
     * Params same as $fields array :
     * $data = ['reseller_id'=>'123, 'client_id'=>'123, ....]
     * 
     * Example:
     * clientsFunctions(method:'client_add', data:['client_id'=>'123','reseller_id'=>'123'....])
     */
    private function clientsFunctions(?string $method, ?array $data = null)
    {
        // set the fields of clientFunctions
        $fields = [
            'client_id',
            'reseller_id',
            'company_name',
            'contact_name',
            'customer_no',
            'vat_id',
            'street',
            'zip',
            'city',
            'state',
            'country',
            'telephone',
            'mobile',
            'fax',
            'email',
            'internet',
            'icq',
            'notes',
            'default_mailserver',
            'limit_maildomain',
            'limit_mailbox',
            'limit_mailalias',
            'limit_mailaliasdomain',
            'limit_mailforward',
            'limit_mailcatchall',
            'limit_mailrouting',
            'limit_mailfilter',
            'limit_fetchmail',
            'limit_mailquota',
            'limit_spamfilter_wblist',
            'limit_spamfilter_user',
            'limit_spamfilter_policy',
            'default_webserver',
            'limit_web_ip',
            'limit_web_domain',
            'limit_web_quota',
            'web_php_options',
            'limit_web_subdomain',
            'limit_web_aliasdomain',
            'limit_ftp_user',
            'limit_shell_user',
            'ssh_chroot',
            'limit_webdav_user',
            'default_dnsserver',
            'limit_dns_zone',
            'limit_dns_slave_zone',
            'limit_dns_record',
            'default_dbserver',
            'limit_database',
            'limit_cron',
            'limit_cron_type',
            'limit_cron_frequency',
            'limit_traffic_quota',
            'limit_client',
            'parent_client_id',
            'username',
            'password',
            'language',
            'usertheme',
            'template_master',
            'template_additional',
            'created_at',
            'added_by',
            'canceled',
        ];
        // define the fields to invoke
        if (null !== $data) {
            foreach ($fields as $key) {
                if (in_array($key, array_keys($data))) {
                    $param[$key] = $data[$key];
                }
            }
        }
        $data = (isset($param)) ? $param : $data;
        return $this->makeCall($method, $data);
    }

    /**
     * Method for DNS
     *
     * @param string $method remote method
     * @param array|null $data [params to be passed method]
     * @return response
     *
     * $method =
     *  The dns metod are 'zone','a','aaaa','alias','cname','hinfo','mx','naptr','ns','ptr','rp','srv','txt' and action can be add, delete, get, update
     *  The structure of name method are dns_type_action:
     *  - dns_a_add    : Adds a dns IPv4 record if type is a to a zone and Returns the ID of the newly added IPv4 resource record
     *  - dns_a_delete : Deletes target dns IPv4 resource record and Returns the number of deleted records
     *  - dns_a_get    : Retrieves information about target dns IPv4 resource record. Returns all fields and values of the chosen dns IPv4 resource record.
     *  - dns_a_update : Updates an IPv4 record if type is a. Returns the number of affected rows.
     *  - dns_templatezone_add : [$client_id, $template_id]
     *  - dns_templatezone_get_all
     *  - dns_zone_get_by_user: [$client_id, $server_id]
     */
    private function dnsFunctions(?string $method, ?array $data = null)
    {
        return $this->makeCall($method, $data);
    }

    /**
     * Domain Method
     *
     * @param string $method
     * @param array $data [params to be passed method]
     * @return response
     *
     * $method :
     * The method to be call
     * - domains_domain_add($client_id, $params) Adds a new domain. Returns the ID of the newly added domain.
     * - domains_domain_delete($primary_id) Deletes a domain. Returns the number of deleted records.
     * - domains_domain_get($primary_id) Retrieves information about a domain. Returns all fields and values of the chosen domain.
     * - domains_get_all_by_user($group_id) Returns information about the domains of the system group. Returns an array with the domain parameters' values.
     *
     * $data:
     * [
     *   $client_id,
     *   [
     *     'domain' => domain name,
     *   ], // params to be passedto new domain
     *   $primary_id,
     *   $group_id
     * ]
     */
    private function domainsFunctions(?string $method, ?array $data = null)
    {
        return $this->makeCall($method, $data);
    }

    /**
     * Method for Server
     *
     * - 'method' => 'server_get' Gets the server configuration by server id and section as 'web', 'dns', 'mail', 'dns', 'cron',  etc.
     * - 'method' => 'server_get_all' Gets a list of all servers
     * - 'method' => 'server_get_app_version' Gets version ISPConfig
     * - 'method' => 'server_get_functions' Gets the functions of a server by server_id
     * - 'method' => 'server_get_functions' Gets php versions by server id and php (php-fpm, fast-cgi)
     * - 'method' => 'server_get_serverid_by_ip' Get server id by ip address
     * - 'method' => 'server_get_serverid_by_name' Get server id by his name
     * - 'method' => 'server_ip_add' Add a IP address record and client id and params ['server_id' => id, 'client_id' => client_id, 'ip_type' => 'IPv4 or IPv6', 'ip_address' => ip, 'virtualhost' => 'y or n', 'virtualhost_port' => '80,443']
     * - 'method' => 'server_ip_delete' Delete IP address record by his ip id
     * - 'method' => 'server_ip_get' Get server ips by id
     * - 'method' => 'server_ip_update' Update IP address record by client id and ip id params ['server_id' => id, 'client_id' => client_id, 'ip_type' => 'IPv4 or IPv6', 'ip_address' => ip, 'virtualhost' => 'y or n', 'virtualhost_port' => '80,443']
     *
     * @param string $method
     * @param array|null $data
     * @return response
     *
     * Example:
     * serversFunctions(method:'server_get', data: [$server_id, $section])
     */
    private function serversFunctions(?string $method, ?array $data = null)
    {
        return $this->makeCall($method, $data);
    }

    /**
     * Method for Sites
     *
     * @param string $method
     * @param array|null $data
     * @return boolean response
     */
    private function sitesFunctions(?string $method, ?array $data = null)
    {
        return $this->makeCall($method, $data);
    }

    /**
     * Method for Mails
     *
     * @param string $method
     * @param array|null $data
     * @return boolean response
     */
    private function mailsFunctions(?string $method, ?array $data = null)
    {
        return $this->makeCall($method, $data);
    }
}
