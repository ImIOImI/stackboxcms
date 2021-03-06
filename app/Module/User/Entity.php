<?php
namespace Module\User;
use Stackbox;
use Spot;

class Entity extends Stackbox\EntityAbstract
{
    // Table
    protected static $_datasource = "cms_users";
    
    /**
     * Fields
     */
    public static function fields()
    {
        return array(
            'id' => array('type' => 'int', 'primary' => true, 'serial' => true),
            'username' => array('type' => 'string', 'required' => true, 'unique' => true),
            'password' => array('type' => 'password', 'required' => true),
            'salt' => array('type' => 'string', 'length' => 42, 'required' => true),
            'email' => array('type' => 'email', 'required' => true),
            'is_admin' => array('type' => 'boolean', 'default' => 0),
            'date_created' => array('type' => 'datetime')
        ) + parent::fields();
    }
    

    /**
     * Relations
     */
    public static function relations()
    {
        return array(
            // User session/login
            'session' => array(
                'type' => 'HasOne',
                'entity' => 'Module\User\Session\Entity',
                'where' => array('user_id' => ':entity.id'),
                'order' => array('date_created' => 'DESC')
            )
        ) + parent::relations();
    }
    
    
    /**
     * Save with salt and encrypted password
     */
    public function beforeSave(Spot\Mapper $mapper)
    {
        $data = $mapper->data($this);
        
        // If password has been modified or set for the first time
        if(isset($this->_dataModified['password']) && ($this->_data['password'] != $this->_dataModified['password'])) {
            $this->__set('salt', $this->randomSalt());
            $this->__set('password', $this->encryptedPassword($this->_dataModified['password']));
        }
        
        parent::beforeSave($mapper);
    }
    
    
    /**
     * Is user logged-in?
     *
     * @return boolean
     */
    public function isLoggedIn()
    {
        return $this->__get('id') ? true : false;
    }
    
    
    /**
     * Is user admin? (Has all rights)
     *
     * @return boolean
     */
    public function isAdmin()
    {
        return (boolean) $this->__get('is_admin');
    }
    
    
    /**
     * Return existing salt or generate new random salt if not set
     */
    public function randomSalt()
    {
        $length = 42;
        $string = "";
        $possible = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789`~!@#$%^&*()[]{}<>-_+=|\/;:,.";
        $possibleLen = strlen($possible);
         
        for($i=0;$i < $length;$i++) {
            $char = $possible[mt_rand(0, $possibleLen-1)];
            $string .= $char;
        }
        
        return $string;
    }
    
    
    /**
     * Encrypt password
     *
     * @param string $pass Password needing encryption
     * @return string Encrypted password with salt
     */
    public function encryptedPassword($pass)
    {
        // Hash = <salt>:<password>
        return hash('sha256', $this->__get('salt') . ':' . $pass);
    }
}