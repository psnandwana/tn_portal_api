<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link      http://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Event\Event;
use Cake\Core\Configure;
use Cake\Error\Debugger;
use Cake\Controller\Component\AuthComponent;
use Cake\Routing\Router;
use Cake\ORM\TableRegistry;
use Cake\View\Helper\SessionHelper;
use Cake\I18n\I18n;


class AppController extends Controller
{
    public function initialize()
    {
        parent::initialize();
        $this->loadComponent('RequestHandler');
        $this->loadComponent('Flash');

    }

    public $AuthUser = false;
    public function beforeFilter(Event $event)
    {
        
    }

    public function beforeRender(Event $event)
    {
        /*load admin lte theme*/
        $this->set('theme', Configure::read('Theme'));
        $this->viewBuilder()->theme('AdminLTE');
        //$this->viewBuilder()->layout('top');
        if (!array_key_exists('_serialize', $this->viewVars) &&
            in_array($this->response->type(), ['application/json', 'application/xml'])
        ) {
            $this->set('_serialize', true);
        }
    }

    

}
