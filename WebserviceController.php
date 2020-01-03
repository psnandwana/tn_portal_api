<?php
namespace App\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\Filesystem\File;
use Cake\Filesystem\Folder;
use Cake\I18n\Time;
use Cake\Mailer\Email;
use Cake\ORM\TableRegistry;
use RestApi\Controller\ApiController;
use RestApi\Utility\JwtToken;

session_start();
class WebserviceController extends ApiController
{
    public function initialize()
    {
        parent::initialize();
        header("Access-Control-Allow-Origin" . $_SERVER['HTTP_ORIGIN']);
        $this->userID = $_SESSION['ipac_uid'];
    }

    public $front_url = 'https://www.fieldopsview.com/tn/contact_sourcing_portal/';

    public function getClientIp($defaultIP = '127.0.0.1')
    {
        $ipaddr = null;
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ipaddr = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipaddr = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ipaddr = $_SERVER['REMOTE_ADDR'];
        }
        $ipaddr = trim($ipaddr);
        if ($ipaddr == '::1') {
            $ipaddr = $defaultIP;
        }
        return $ipaddr;
    }

    /** Login API */
    public function login()
    {
        $this->request->allowMethod('post');
      
        if (empty($this->request->data['username'])) {
            $this->httpStatusCode = 422;
            $this->apiResponse['message'] = 'Username is required';
        } else if (empty($this->request->data['password'])) {
            $this->httpStatusCode = 422;
            $this->apiResponse['message'] = 'Password is required';
        } else {
            $username = $this->request->data['username'];
            $username = trim($username);
            $password_text = $this->request->data['password'];
            $password = md5($password_text);

            $checkUser = TableRegistry::get('tn_admin_user');
            $checkUser = $checkUser->find('all')->where(['email' => $username, 'password' => $password])->toArray();

            if (count($checkUser) > 0) {
                unset($checkUser[0]['password']);
                $ip = $this->getClientIp();
                $payload = ['username' => $username, 'time' => Time::now()];
                $token = JwtToken::generateToken($payload);
                // SET SESSION AFTER LOGIN
                $_SESSION['ipac_token'] = $token;
                $_SESSION['ipac_uid'] = $checkUser[0]['id'];
                $_SESSION['ipac_uemail'] = $checkUser[0]['email'];
                $_SESSION['ipac_uname'] = $checkUser[0]['name'];
                $checkUser[0]['super_admin'] = (string) $checkUser[0]['super_admin'];
                $checkUser[0]['moderator'] = (string) $checkUser[0]['moderator'];
                                
                $this->httpStatusCode = 200;
                $this->apiResponse['userinfo'] = $checkUser[0];
                $this->apiResponse['message'] = 'Login successfully';
            } else {
                $this->httpStatusCode = 422;
                $this->apiResponse['message'] = 'Invalid Username or Password';
            }
        }
    }

    /** Logout API */
    public function logout()
    {
        $this->request->allowMethod('post');
        unset($_SESSION['ipac_token']);
        unset($_SESSION['ipac_uid']);
        session_destroy();
        $this->httpStatusCode = 200;
        $this->apiResponse['message'] = 'Logout successfully.';
    }

    /** Forget Password API */
    public function forgetPassword()
    {
        $this->request->allowMethod('post');
        $connection = ConnectionManager::get('default');
        if (!empty($this->request->data)) {
            $email = $this->request->data['email'];
            $tn_admin_user = TableRegistry::get('tn_admin_user');
            $checkUser = $tn_admin_user->find('all')->where(['email' => $email]);
            $checkUser = $checkUser->toList();
            if (count($checkUser) > 0) {
                $FirstName = $checkUser[0]['name'];
                $otp = getToken(12);

                $dir = new Folder(WWW_ROOT . 'templates');
                $files = $dir->find('welcome.html', true);
                foreach ($files as $file) {
                    $file = new File($dir->pwd() . DS . $file);
                    $contents = $file->read();
                    $file->close();
                }
                $emails_content = $contents;

                $patterns = array();
                $outputs = preg_replace($patterns, '', $emails_content);
                $message = str_replace(array('{APP_NAME}', '{TITLE}', '{FIRSTNAME}', '{BODY}'),
                    array('Contact Sourcing Portal', 'You have requested to reset your password', $FirstName, '<p style="text-align:justify;font-size: 14px;">We cannot simply send you your old password. A unique link to reset your password has been generated for you. To reset your password, click the following link and follow the instructions</p>
                        </br><p style="text-align:center"><a class="mailpoet_button" style="display: inline-block; -webkit-text-size-adjust: none; mso-hide: all; text-decoration: none; text-align: center; background-color: #41c1f2; border-radius: 11px; width: 218px; line-height: 40px; color: #ffffff; font-family: Verdana, Geneva, sans-serif; font-size: 18px; font-weight: normal; border: 1px solid #0ea8e4;" href="' . $this->front_url . 'password/create/' . $otp . '"> Reset Password </a></p>'), $outputs);
                $mail = new Email();

                $mail->transport('Gmail');

                $mail->emailFormat('html')
                    ->from(['info@indianpac.com' => 'Contact Sourcing Portal'])
                    ->to([$email])
                    ->subject('Reset Your Password')
                    ->send($message);

                $checkOTP = $connection->execute("select * from tn_forgot_password where userid='" . $email . "'")->fetchAll('assoc');

                if (count($checkOTP) > 0) {
                    $connection->update('tn_forgot_password', ['secret_key' => $otp, 'is_updated' => 0], ['userid' => $email]);
                } else {
                    $connection->insert('tn_forgot_password', ['userid' => $email, 'secret_key' => $otp]);
                }
                $this->httpStatusCode = 200;
                $this->apiResponse['secret_key'] = $otp;
                $this->apiResponse['message'] = 'Reset password link has sent to your email address.';
            } else {
                $this->httpStatusCode = 422;
                $this->apiResponse['message'] = 'Oops! Your email is not registered with us.';
            }
        } else {
            $this->httpStatusCode = 422;
            $this->apiResponse['message'] = 'Please enter valid email address.';
        }
    }

    /** RESET PASSWORD API */
    public function resetpassword()
    {
        
        $this->request->allowMethod('post');
        $connection = ConnectionManager::get('default');
        $secret_key = $this->request->data['secret_key'];
        $password = md5($this->request->data['password']);
        $tn_forgot_password = TableRegistry::get('tn_forgot_password');
        $getUser = $tn_forgot_password->find('all')->where(['secret_key' => $secret_key]);
        $getUser = $getUser->toList();
        if (!empty($getUser)) {
            $connection->update('tn_admin_user', ['password' => $password], ['email' => $getUser[0]['userid']]);
            $this->httpStatusCode = 200;
            $this->apiResponse['message'] = 'Password has been reset successfully.';
        } else {
            $this->httpStatusCode = 422;
            $this->apiResponse['message'] = 'Your link has been expired.';
        }
    }

    /**Verify Token  */
    public function verifytoken()
    {
        $this->request->allowMethod('post');
        if ($this->checkToken()) {
            if (isset($_SESSION['ipac_uid'])) {
                $user_id = $_SESSION['ipac_uid'];
                // $user_id = $this->userID;
                $checkUser = TableRegistry::get('tn_admin_user');
                $checkUser = $checkUser->find('all')->where(['id' => $user_id]);
                $checkUser = $checkUser->toArray();
                if (count($checkUser) > 0) {
                    unset($checkUser[0]['password']);
                    $checkUser[0]['super_admin'] = (string) $checkUser[0]['super_admin'];
                    $checkUser[0]['moderator'] = (string) $checkUser[0]['moderator'];
                    $this->httpStatusCode = 200;
                    $this->apiResponse['message'] = "successfully fetched data";
                    $this->apiResponse['userinfo'] = $checkUser[0];
                } else {
                    $this->httpStatusCode = 403;
                    $this->apiResponse['message'] = "Please login again";
                }
            } else {
                $this->httpStatusCode = 403;
                $this->apiResponse['message'] = "your session has been expired";
            }
        } else {
            $this->httpStatusCode = 403;
            $this->apiResponse['message'] = "your session has been expired";
        }
    }

    /** GET User Listing */
    public function getusers()
    {
        if ($this->checkToken()) {

            $this->request->allowMethod('post');
            $data = array();
            $page = 1; // Default page start with 1
            $limit = 10; // Set Limit according to you 1 - 5 -10
            $start = 0;
            if (isset($this->request->data['page'])) {
                $page = $this->request->data['page']; // Request page no eg. 1,2,3,4,5
                if (!is_numeric($page)) {
                    $page = 1;
                }
                $start = ($page - 1) * $limit;
            }
            $user_id = $this->request->data['user_id'];
            $User = TableRegistry::get('tn_admin_user');
            $numUsers = $User->find('all', array('conditions' => array('id !=' => $user_id)))->count();
            $userList = $User->find('all', array('fields' => array('id', 'name', 'email', 'super_admin', 'moderator'), 'conditions' => array('id !=' => $user_id), 'order' => ['id' => 'DESC LIMIT ' . $start . ',' . $limit]))->toArray();
            if (count($userList)) {
                $data = $userList;
            }

            $this->httpStatusCode = 200;
            $this->apiResponse['page'] = (int) $page;
            $this->apiResponse['total'] = (int) $numUsers;
            $this->apiResponse['message'] = "successfully fetched data";
            $this->apiResponse['users'] = $data;
        } else {
            $this->httpStatusCode = 403;
            $this->apiResponse['message'] = "your session has been expired";
        }
    }

    /** Create user */
    public function createuser()
    {
        if ($this->checkToken()) {
            $this->request->allowMethod('post');
            $User = TableRegistry::get('tn_admin_user');
            $data = $this->request->data;
            $name = $data['name'];
            $email = $data['email'];
            $super_admin = $data['super_admin'];
            $moderator = $data['moderator'];
           
            if ($super_admin == 1) {
                $moderator = 1;
            }

            $password = getToken(6);
            // $current_date = Time::now();

            if (empty($email)) {

                $this->httpStatusCode = 422;
                $this->apiResponse['message'] = 'Email is required field.';

            } else if (empty($name)) {

                $this->httpStatusCode = 422;
                $this->apiResponse['message'] = 'Name is required field.';

            } else {
                $userList = $User->find('all')->where(['email' => $email])->toArray();
                if (count($userList) > 0) {
                    $this->httpStatusCode = 422;
                    $this->apiResponse['message'] = 'Email already exist.';
                } else {
                    $queryInsert = $User->query();
                    $queryInsert->insert(['name', 'email', 'password', 'super_admin', 'moderator', 'created_at'])
                        ->values([
                            'name' => $name,
                            'email' => $email,
                            'password' => md5($password),
                            'super_admin' => $super_admin,
                            'moderator' => $moderator,
                            'created_at' => Time::now(),
                        ])
                        ->execute();

                    $FirstName = $name;

                    $dir = new Folder(WWW_ROOT . 'templates');
                    $files = $dir->find('welcome.html', true);
                    foreach ($files as $file) {
                        $file = new File($dir->pwd() . DS . $file);
                        $contents = $file->read();
                        $file->close();
                    }
                    $emails_content = $contents;

                    $patterns = array();
                    $outputs = preg_replace($patterns, '', $emails_content);
                    $message = str_replace(array('{APP_NAME}', '{TITLE}', '{FIRSTNAME}', '{BODY}'),
                        array('Contact Sourcing Portal', 'Login Credentials', $FirstName, '<p style="text-align:justify;font-size: 14px;">Below is your login details to access Contact Sourcing Portal control panel:</p>
                            <p style="font-size: 14px;"><strong>Username:</strong> ' . $email . '</p>
                            <p style="font-size: 14px;"><strong>Password:</strong> ' . $password . '</p>
                            </br><p style="text-align:center;"><a class="mailpoet_button" style="display: inline-block; -webkit-text-size-adjust: none; mso-hide: all; text-decoration: none; text-align: center; background-color: #41c1f2; border-radius: 11px; width: 218px; line-height: 40px; color: #ffffff; font-family: Verdana, Geneva, sans-serif; font-size: 18px; font-weight: normal; border: 1px solid #0ea8e4;" href="' . $this->front_url . 'admin">CLICK HERE TO LOGIN</a></p>'), $outputs);

                    $mail = new Email();
                    $mail->transport('Gmail');
                    $mail->emailFormat('html')
                        ->from(['info@indianpac.com' => 'Contact Sourcing Portal'])
                        ->to([$email])
                        ->subject('Contact Sourcing Portal - Login Credentials')
                        ->send($message);

                    $this->httpStatusCode = 200;
                    $this->apiResponse['message'] = 'New user has been created successfully.';
                }
            }
        } else {
            $this->httpStatusCode = 403;
            $this->apiResponse['message'] = "your session has been expired";
        }
    }

    /** Edit user */
    public function updateuser()
    {
        
        if ($this->checkToken()) {

            $this->request->allowMethod('post');
            $User = TableRegistry::get('tn_admin_user');
            $data = $this->request->data;
            $email = $data['email'];
            $super_admin = $data['super_admin'];
            $moderator = $data['moderator'];
            if ($super_admin == 1) {
                $moderator = 1;
            }

            $queryUpdate = $User->query();
            $queryUpdate->update()
                ->set([
                    'super_admin' => $super_admin,
                    'moderator' => $moderator
                ])
                ->where(['email' => $email])
                ->execute();
            $this->httpStatusCode = 200;
            $this->apiResponse['message'] = 'User details has been updated successfully.';
        } else {
            $this->httpStatusCode = 403;
            $this->apiResponse['message'] = "your session has been expired";
        }
    }

    /** Delete user */
    public function deleteuser()
    {
        if ($this->checkToken()) {
            $this->request->allowMethod('post');
            $data = $this->request->data;
            $user_id = $data['user_id'];
            $User = TableRegistry::get('tn_admin_user');
            $entity = $User->get($user_id);
            $result = $User->delete($entity);
            $this->httpStatusCode = 200;
            $this->apiResponse['message'] = 'Deleted successfully.';
        } else {
            $this->httpStatusCode = 403;
            $this->apiResponse['message'] = "your session has been expired";
        }
    }

    public function getdistricts()
    {
        if ($this->checkToken()) {
            $options = array();
           
            $options['fields'] = array('district' => 'DISTINCT district');
            $options['conditions']['user_id'] = $this->userID;
            $Tbldistrict = TableRegistry::get('District', ['table' => 'tn_user_district']);
            $district = $Tbldistrict->find('all', $options)->toArray();
            $tmp_array = array();
            if(count($district) > 0){
                foreach ($district as $value) {
                    $tmp_array[] = trim($value['district']);
                }
            }else{
               
                unset($options['conditions']);
                $options['fields'] = array('district' => 'DISTINCT Influencer.district');
                $Tbldistrict = TableRegistry::get('Influencer', ['table' => 'tn_disctrictwise_influencers']);
                $district = $Tbldistrict->find('all', $options)->toArray();
               
                foreach ($district as $value) {
                    $tmp_array[] = trim($value['district']);
                }
            }
            
            
            $this->httpStatusCode = 200;
            $this->apiResponse['districts'] = $tmp_array;
        } else {
            $this->httpStatusCode = 403;
            $this->apiResponse['message'] = "your session has been expired";
        }        
    }

    public function getinfluencers()
    {
        if ($this->checkToken()) 
        {
            $this->request->allowMethod('post');
            $data = array();
            $page = 1; // Default page start with 1
            $limit = 25; // Set Limit according to you 1 - 5 -10
            $start = 0;
            if (isset($this->request->data['page'])) {
                $page = $this->request->data['page']; // Request page no eg. 1,2,3,4,5
                if (!is_numeric($page)) {
                    $page = 1;
                }
                $start = ($page - 1) * $limit;
            }
            $district = $this->request->data['district'];
            $contact_type = $this->request->data['contact_type'];
            $searchString = $this->request->data['search_keyword'];
            $condition = array();
            if ($searchString != '') {
                $condition['or'] = array( 
                    'name LIKE' => '%' . $searchString . '%',
                    'type LIKE' => '%' . $searchString . '%',
                    'category LIKE' => '%' . $searchString . '%',
                    'area LIKE' => '%' . $searchString . '%',
                    'contact_no LIKE' => '%' . $searchString . '%',
                    'email LIKE' => '%' . $searchString . '%',
                    'address LIKE' => '%' . $searchString . '%',
                    'ac_name LIKE' => '%' . $searchString . '%'
                );
            }
            dd($contact_type);
            if($contact_type == 'Influencer'){
                $condition['district'] = $district;
                $User = TableRegistry::get('tn_disctrictwise_influencers');
            }if($contact_type == 'BLO'){
                $condition['district'] = $district;
                $User = TableRegistry::get('tn_disctrictwise_blo');
            }else{
                if ($searchString != '') {
                    $condition['or']['poc LIKE'] = '%' . $searchString . '%';
                }
                $condition['district'] = $district;
                $User = TableRegistry::get('tn_disctrictwise_organizations');
            }
            
            $numUsers = $User->find('all', array('conditions' => $condition))->count();
            
            $userList = $User->find('all', array('conditions' => $condition, 'order' => ['id' => 'DESC LIMIT ' . $start . ',' . $limit]))->toArray();
            
            if (count($userList)) {
                $data = $userList;
            }
            $this->httpStatusCode = 200;
            $this->apiResponse['page'] = (int) $page;
            $this->apiResponse['total'] = (int) $numUsers;
            $this->apiResponse['data'] = $data;
        } else {
            $this->httpStatusCode = 403;
            $this->apiResponse['message'] = "your session has been expired";
        }
    }
}

//FOR Debugging purpose
function dd($data)
{
    echo '<pre>';
    print_r($data);exit;
    echo '</pre>';
}

function customdateformat($chkdt)
{
    $month = substr($chkdt, 4, 3);
    if ($month == 'Jan') {
        $month = '01';
    } else if ($month == 'Feb') {
        $month = '02';
    } else if ($month == 'Mar') {
        $month = '03';
    } else if ($month == 'Apr') {
        $month = '04';
    } else if ($month == 'May') {
        $month = '05';
    } else if ($month == 'Jun') {
        $month = '06';
    } else if ($month == 'Jul') {
        $month = '07';
    } else if ($month == 'Aug') {
        $month = '08';
    } else if ($month == 'Sep') {
        $month = '09';
    } else if ($month == 'Oct') {
        $month = '10';
    } else if ($month == 'Nov') {
        $month = '11';
    } else if ($month == 'Dec') {
        $month = '12';
    }

    $date = substr($chkdt, 7, 3);
    $year = substr($chkdt, 10, 5);
    return date("Y-m-d", mktime(0, 0, 0, $month, $date, $year));
}
function crypto_rand_secure($min, $max)
{
    $range = $max - $min;
    if ($range < 1) {
        return $min;
    }
    // not so random...
    $log = ceil(log($range, 2));
    $bytes = (int) ($log / 8) + 1; // length in bytes
    $bits = (int) $log + 1; // length in bits
    $filter = (int) (1 << $bits) - 1; // set all lower bits to 1
    do {
        $rnd = hexdec(bin2hex(openssl_random_pseudo_bytes($bytes)));
        $rnd = $rnd & $filter; // discard irrelevant bits
    } while ($rnd > $range);
    return $min + $rnd;
}

function getToken($length)
{
    $token = "";
    $codeAlphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $codeAlphabet .= "abcdefghijklmnopqrstuvwxyz";
    $codeAlphabet .= "0123456789";
    $max = strlen($codeAlphabet); // edited

    for ($i = 0; $i < $length; $i++) {
        $token .= $codeAlphabet[crypto_rand_secure(0, $max - 1)];
    }
    return $token;
}

function moneyFormatIndia($num)
{
    $explrestunits = "";
    if (strlen($num) > 3) {
        $lastthree = substr($num, strlen($num) - 3, strlen($num));
        $restunits = substr($num, 0, strlen($num) - 3); // extracts the last three digits
        $restunits = (strlen($restunits) % 2 == 1) ? "0" . $restunits : $restunits; // explodes the remaining digits in 2's formats, adds a zero in the beginning to maintain the 2's grouping.
        $expunit = str_split($restunits, 2);
        for ($i = 0; $i < sizeof($expunit); $i++) {
            // creates each of the 2's group and adds a comma to the end
            if ($i == 0) {
                $explrestunits .= (int) $expunit[$i] . ","; // if is first value , convert into integer
            } else {
                $explrestunits .= $expunit[$i] . ",";
            }
        }
        $thecash = $explrestunits . $lastthree;
    } else {
        $thecash = $num;
    }
    return $thecash; // writes the final format where $currency is the currency symbol.
}
