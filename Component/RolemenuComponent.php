<?php
namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use Cake\ORM\TableRegistry;
use Cake\Network\Exception\InternalErrorException;
use Cake\Utility\Text;

/**
 * Upload component
 */
class RolemenuComponent extends Component
{
    public function setmenu($roleid)
    {
        $mm = TableRegistry::get("TblAdminRolePermissionMasters");
        $menus_json = $mm->find('All')->where(['iRoleId' => $roleid])->first();
        if(count($menus_json)>0) {
            $menus = json_decode($menus_json->tPermissionDetails);

            $final_menu = array();
            foreach ($menus as $m) {
                $module = TableRegistry::get("TblModuleMaster");
                $moduledata = $module->find('All')->where(['iModuleID' => $m->menu])->first();
                $actions=[];
                if(isset($m->submenu)) {
                    foreach ($m->submenu as $mm) {
                        $modulemenu = TableRegistry::get("TblModuleMenuMasters");
                        $modulemenudata = $modulemenu->find('All')->where(['iModuleMenuID' => $mm]);
                        $actions[] = $modulemenudata;
                    }
                }
                $title = 'No_Title';
                if ($moduledata->iMtId != 0) {
                    $modtitle = TableRegistry::get("TblModuleTitleMasters");
                    $modtitledata = $modtitle->find('All')->where(['iMtId' => $moduledata->iMtId])->first();
                    $title = $modtitledata->vName;
                }
                    //$final_menu[$title][] = array('Module_Name' => $moduledata->vModuleName, 'Module_Id' => $moduledata->iModuleID, 'Module_Controller' => $moduledata->vModuleDescription, 'Module_Class' => $moduledata->vIconClass,'Actions'=>$actions);
                $final_menu[$title][] = array('Module_Name' => $moduledata->vModuleName, 'Module_Id' => $moduledata->iModuleID, 'Module_Controller' => $moduledata->vModuleAlias, 'Module_Class' => $moduledata->vIconClass,'Actions'=>$actions);
            }
            return $final_menu;
        }
    }
    public function getAuthorizationData($roleid,$controller,$action){
        $get_module = TableRegistry::get("TblModuleMaster");
        $module_id = $get_module->find('All')
            ->select(['iModuleID'])
            ->where(['vController =' => $controller])
            ->first();

        // get module id for fetch menu
        $module_id = $module_id->iModuleID;

        $permission_data = TableRegistry::get("TblAdminRolePermissionMasters");
        $moduledata = $permission_data->find('All')
            ->select(['tPermissionDetails'])
            ->where(['iRoleId =' => $roleid])
            ->first();

        $menu_data = json_decode($moduledata->tPermissionDetails, true);

        //search menu id from json data
        $key = array_search($module_id, array_column($menu_data, 'menu'));
        if(is_numeric($key))
        {
            //check module id is exist or not in json menu data array key
            if (array_key_exists($key,$menu_data))
            {
                $current_auth_controller =$menu_data[$key];
                $menu_id = $current_auth_controller['menu'];
                $data = TableRegistry::get("TblModuleMenuMasters");
                $module_all_actions = $data->find()
                    ->select(['vModuleMenuLink'])
                    ->where(['iModuleID' => $menu_id])
                    ->all()
                    ->toArray();

                foreach($module_all_actions as $row)
                {
                    $all_actions[] = $row['vModuleMenuLink'];
                }

                //check submodule exist or not in sub module array
                if(array_key_exists('submenu',$current_auth_controller))
                {
                    //user not have full access of the controller

                    $sub_menu = $current_auth_controller['submenu'];

                    /*$sub_menu_ids = implode(',',$sub_menu);
                    echo $sub_menu_ids;*/


                    $data = TableRegistry::get("TblModuleMenuMasters");
                    $module_action = $data->find()
                        ->select(['vModuleMenuLink'])
                        ->where(['iModuleMenuID IN' => $sub_menu])
                        ->all();
                    foreach($module_action as $row)
                    {
                        $actions_array[] =$row['vModuleMenuLink'];
                    }

                    $action_deny = array_diff($all_actions,$actions_array);
                    $action_deny = array_values($action_deny);
                    $action_allow =array_values(array_intersect($all_actions,$actions_array));
                    $access_data = array();
                    $access_data['action_allow'] = $action_allow;
                    $access_data['action_deny'] = $action_deny;
                    //$deny_actions = implode(',',$action_deny);
                }
                else{
                    $access_data['action_allow'] = $all_actions;
                    $access_data['action_deny'] = array();
                }
            }
            else
            {
                $access_data['action_allow'] = array();
                $access_data['action_deny'] = array();
            }
        }
        else{
                $access_data['action_allow'] = array();
                $access_data['action_deny'] = array();
        }
       return $access_data;
    }
}