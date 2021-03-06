<?php

namespace Auth3\Repositories;

use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Auth3\Entities\UserEntity;
use Auth3\Entities\ClientEntity;
use Auth3\Database\Database;
use Auth3\Util\Recaptcha;

class UserRepository implements UserRepositoryInterface {

	/**
     * Get a user entity.
     *
     * @param string                $username
     * @param string                $password
     * @param string                $grantType    The grant type used
     * @param ClientEntityInterface $clientEntity
     *
     * @return UserEntityInterface
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    ) {
    	// check username, password against database
    	// check granttype against client to see if user is permitted to use grant type with the client

    	// if valid, return instance of Auth3\UserEntity (UserEntityInterface)
    	// else return null

        $db = Database::getDatabase(); // pdo instance

        $stmt = $db->prepare("SELECT * FROM auth3_users WHERE email = :username LIMIT 1");
        $stmt->execute(compact('username'));

        if ($user = $stmt->fetch()) {

            $id = $user['id'];
            $uuid = $user['uuid'];
            $firstname = $user['first_name'];
            $familyname = $user['family_name'];
            $joindate = $user['join_date'];
            $password_hash = $user['password'];
            $gAuthCode = $user['twofactor'];
            $hasTwoFactor = $user['using_twofactor'] == 1;
            $verified = $user['verification_status'];

    		if (password_verify($password, $password_hash)) {
    			return new UserEntity($id, $uuid, $username, $firstname, $familyname, $hasTwoFactor, $gAuthCode, $verified, $joindate);
    		}

    	}
    	return null;
    }

    /**
    * @return UserEntityInterface
     */
    public function getUserEntityByIdentifier($identifier) {
        $db = Database::getDatabase();

        $stmt = $db->prepare("SELECT * FROM auth3_users WHERE id = :identifier LIMIT 1");
        $stmt->execute(compact('identifier'));

        if ($user = $stmt->fetch()) {

            $uuid = $user['uuid'];
            $username = $user['email'];
            $firstname = $user['first_name'];
            $familyname = $user['family_name'];
            $joindate = $user['join_date'];
            $gAuthCode = $user['twofactor'];
            $hasTwoFactor = $user['using_twofactor'] == 1;
            $verified = $user['verification_status'];
            
            return new UserEntity($identifier, $uuid, $username, $firstname, $familyname, $hasTwoFactor, $gAuthCode, $verified, $joindate);
        }
        return null;
    }

    /**
    * @return UserEntityInterface
    */
    public function getUserEntityByEmail($email) {
        $db = Database::getDatabase();

        $stmt = $db->prepare("SELECT * FROM auth3_users WHERE email = :email LIMIT 1");
        $stmt->execute(compact('email'));

        if ($user = $stmt->fetch()) {

            $identifier = $user['id'];
            $uuid = $user['uuid'];
            $username = $user['email'];
            $firstname = $user['first_name'];
            $familyname = $user['family_name'];
            $joindate = $user['join_date'];
            $gAuthCode = $user['twofactor'];
            $hasTwoFactor = $user['using_twofactor'] == 1;
            $verified = $user['verification_status'];
            
            return new UserEntity($identifier, $uuid, $username, $firstname, $familyname, $hasTwoFactor, $gAuthCode, $verified, $joindate);
        }
        return null;
    }
    /**
    * @return Array
    */
    public function createUser($email, $password, $recaptcha) {
        if ($this->getUserEntityByEmail($email) != null) { // user already exists, do not create
            return [
                'error' => 'An account with that email already exists.'
            ];
        }
        if (!Recaptcha::verify($recaptcha, $_SERVER['REMOTE_ADDR'])) { // invalid captcha, fail
            return [
                'error' => 'Recaptcha was invalid. Please try again.'
            ];
        }

        // if the user manages to submit 2 different passwords, that's on them for bypassing clientside protections
        $db = Database::getDatabase();
        $hashedpassword = password_hash($password, PASSWORD_DEFAULT);
        if (function_exists('random_bytes')) {
            $bytes = bin2hex(random_bytes(20));
        } else {
            $bytes = bin2hex(openssl_random_pseudo_bytes(20));
        }
        $uuid = \Auth3\lib\UUID::v4();
        $stmt = $db->prepare("INSERT INTO auth3_users (uuid, email, password, verification_status) VALUES (:uuid, :email, :hashedpassword, :bytes)");
        $stmt->execute(compact('uuid', 'email', 'hashedpassword', 'bytes'));

        return [
            'error' => 'success',
            'user' => $this->getUserEntityByEmail($email)
        ];
    }

    public function updateUser($id, $data) {
        $user = $this->getUserEntityByIdentifier($id);
        if ($user == null) return ['error' => 'That account does not exist.'];

        $db = Database::getDatabase();
        // update user name
        if (isset($data['firstname']) && isset($data['familyname'])) {
            $firstname = $data['firstname'];
            $familyname = $data['familyname'];
            if ($firstname == $user->getFirstname() && $familyname == $user->getFamilyname()) { // if the name hasnt changed, dont do anything
                return [
                    'message' => 'User info not updated.'
                ];
            }
            $query = "UPDATE auth3_users SET first_name = :firstname, family_name = :familyname WHERE id = :id LIMIT 1";
            $qdata = compact('firstname', 'familyname', 'id');
        } else if (isset($data['email'])) { // update email
            $email = $data['email'];
            if ($user->getEmail() == 'test@test.test') {
                return [
                    'error' => 'Cannot modify the test email.'
                ];
            } else if ($email == $user->getEmail()) { // if the email hasnt changed, dont do anything
                return [
                    'message' => 'User info not updated.'
                ];
            }
            $this->setEmailVerificationForUser($id, false);
            $query = "UPDATE auth3_users SET email = :email WHERE id = :id LIMIT 1";
            $qdata = compact('email', 'id');
            $event = new \Auth3\Entities\EventLogEntity('user', 'email', $_SERVER['REMOTE_ADDR'] . ' changed to ' . $email, $user->getIdentifier());
        } else if (isset($data['password_old']) && isset($data['password_new']) && isset($data['password_confirm'])) { // check and update password
            // not good, fix
            if ($this->getUserEntityByUserCredentials($user->getEmail(), $data['password_old'], null, new ClientEntity(null, null, null)) == null) return ['error' => 'Password is incorrect.'];
            if ($data['password_new'] != $data['password_confirm']) return ['error' => 'Passwords do not match.'];
            if ($user->getEmail() == 'test@test.test') {
                return [
                    'error' => 'Cannot modify the test password.'
                ];
            }
            $hashedpassword = password_hash($data['password_new'], PASSWORD_DEFAULT);
            $query = "UPDATE auth3_users SET password = :hashedpassword WHERE id = :id LIMIT 1";
            $qdata = compact('hashedpassword', 'id');
            $event = new \Auth3\Entities\EventLogEntity('user', 'password', $_SERVER['REMOTE_ADDR'], $user->getIdentifier());
        }
        if (isset($event)) {
            $logRepository = new \Auth3\Repositories\EventLogRepository();
            $logRepository->addEvent($event);
        }
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($qdata);
            return [
                'message' => 'User info updated.'
            ];
        } catch (PDOException $e) {
            return [
                'error' => $e
            ];
        }
    }

    public function resetUserPassword($id, $data) {
        if ($data['password_new'] != $data['password_confirm']) return ['error' => 'Passwords do not match.'];
        $db = Database::getDatabase();
        $hashedpassword = password_hash($data['password_new'], PASSWORD_DEFAULT);
        $query = "UPDATE auth3_users SET password = :hashedpassword WHERE id = :id LIMIT 1";
        $qdata = compact('hashedpassword', 'id');
        $logRepository = new \Auth3\Repositories\EventLogRepository();
        $event = new \Auth3\Entities\EventLogEntity('user', 'reset_password', $_SERVER['REMOTE_ADDR'], $id);
        $logRepository->addEvent($event);
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($qdata);
            return [
                'message' => 'User info updated.'
            ];
        } catch (PDOException $e) {
            return [
                'error' => $e
            ];
        }
    }

    public function setTwoFactorForUser($id, $secret) {
        $user = $this->getUserEntityByIdentifier($id);
        if ($user == null) return ['error' => 'That account does not exist.'];

        $db = Database::getDatabase();
        $stmt = $db->prepare("UPDATE auth3_users SET twofactor = :secret WHERE id = :id LIMIT 1");
        try {
            $stmt->execute(compact('secret', 'id'));
            return [
                'message' => 'User twofactor updated.'
            ];
        } catch (PDOException $e) {
            return [
                'error' => $e
            ];
        }
    }

    public function setUsingTwoFactorForUser($id, $val) {
        $user = $this->getUserEntityByIdentifier($id);
        if ($user == null) return ['error' => 'That account does not exist.'];

        $val = $val ? '1' : '0';

        $db = Database::getDatabase();
        $stmt = $db->prepare("UPDATE auth3_users SET using_twofactor = :val WHERE id = :id LIMIT 1");
        try {
            $stmt->execute(compact('val', 'id'));
            return [
                'message' => 'User using_twofactor updated.'
            ];
        } catch (PDOException $e) {
            return [
                'error' => $e
            ];
        }
    }

    public function setEmailVerificationForUser($id, $val) {
        $user = $this->getUserEntityByIdentifier($id);
        //print_r($user);
        if ($user == null) return ['error' => 'That account does not exist.'];

        $val = $val ? 'verified' : (function_exists('random_bytes') ? bin2hex(random_bytes(20)) : bin2hex(openssl_random_pseudo_bytes(20)));

        $db = Database::getDatabase();
        $stmt = $db->prepare("UPDATE auth3_users SET verification_status = :val WHERE id = :id LIMIT 1");
        try {
            $stmt->execute(compact('val', 'id'));
            return [
                'message' => 'User verification_status updated.'
            ];
        } catch (PDOException $e) {
            return [
                'error' => $e
            ];
        }
    }

}