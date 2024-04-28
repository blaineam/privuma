<?php

/** Enable login for password-less database
* @link https://www.adminer.org/plugins/#use
* @author Jakub Vrana, https://www.vrana.cz/
* @license https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
* @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
*/
class AdminerLoginPasswordLess
{
    /** @access protected */
    public $password_hash;

    /** Set allowed password
    * @param string result of password_hash
    */
    public function __construct($password_hash)
    {
        $this->password_hash = password_hash($password_hash, PASSWORD_DEFAULT);
    }

    public function credentials()
    {
        $password = get_password();
        return array(SERVER, $_GET['username'], (password_verify($password, $this->password_hash) ? '' : $password));
    }

    public function login($login, $password)
    {
        if ($password != '') {
            return true;
        }
    }

}
