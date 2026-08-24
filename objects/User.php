<?php
// PURGE FROM THIS CODEBASE BUT USE AS AN EXAMPLE FOR IMPLEMENTATION
namespace PHPerformance\Objects;
use PHPerformance\API\Tradovate;
use PHPerformance\Core\Core;
use App\Model\ActionAttempts;
use PHPerformance\Exceptions\RateLimitException;
use PHPerformance\Exceptions\SystemException;
use PHPerformance\Exceptions\UserNotFoundException;
use PHPerformance\Exceptions\InvalidPasswordException;
use App\Model\Users;
use PHPerformance\Core\Database;
use Doctrine\DBAL\ParameterType;
use PHPerformance\Tracking;
use InvalidArgumentException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

// NOTE: while this class extends DatabaseRowTemplate for the core functionality, this class is used only for managing the current user in a session
class User extends DatabaseRowTemplate implements UserInterface, PasswordAuthenticatedUserInterface {
    public ?int $id = null;

    protected function __construct() {}

    /**
     * needs to be included as per DatabaseRowTemplate, but is essentially just combining constructDefault and loginWithId into one call
     * NOTE: sets session user!! Effectively the same as loginWithId
     * @param int $userId
     * @return User
     */
    public static function constructWithId(int $userId): User
    {
        $user = new static();

        // note that this will set session data 
        $user->loginWithId($userId);

        return $user;
    }

    /**
     * Constructs a User object with the provided ID, without setting the user as logged in.
     * This is useful when you want to construct a User object without affecting the current session.
     * @param int $userId The ID of the user to construct.
     * @return User The constructed User object.
     */
    public static function constructWithIdNoLogin(int $userId): User
    {
        $user = new static();
        $user->setUserData($userId);
        return $user;
    }

    /**
     * Set a user as logged in by id. Likely from an auth token
     * @param int $userId
     * @throws \PHPerformance\Exceptions\UserNotFoundException
     * @return void
     */
    public function loginWithId(int $userId)
    {
        $this->setUserData($userId);
        $_SESSION['user_id'] = $this->id;
    }

    private function setUserData(int $userId): void
    {
        if (false === $userData = Users::getUserById($userId)) {
            throw new UserNotFoundException("User with provided ID ($userId) does not exist.");
        }

        $this->id = $userId;
        $this->rowData = $userData;
    }

    /**
     * used for Symfony JWT token authentication to avoid having to query for the ID and then construct the user again with a second query
     * NOTE: in the future, we'll probably want to use a User entity in Symfony for easier integration
     * 
     * @param string $email
     * @return User
     */
    public static function constructWithEmail(string $email): ?User
    {
        $user = new static();

        if (false === $userData = Users::getUserByEmail($email)) {
            return null;
        }

        $user->id = $userData['user_id'];
        $user->rowData = $userData;

        return $user;
    }

    /**
     * Functionally the same as constructWithId, but prevents an extra query if user verification was done before object creation
     * @param array $row db results equivalent to that of Users::getUserById()
     * @return User
     */
    public static function constructWithDbOutput(array $row): User
    {
        $user = new static();
        $user->rowData = $row;

        return $user;
    }

    /**
     * To match DatabaseRowTemplate, constructWithId could not be made flexible to accept null. Cases without user id should be constructed here
     * @return User
     */
    public static function constructDefault(): User
    {
        $user = new static();
        $user->rowData = [];
        $user->loggedIn();

        return $user;
    }

    public static function constructForApi(): User
    {
        $user = new static();
        $user->rowData = [];

        return $user;
    }

    /**
     * Get an individual value from $userData, if exists
     * TODO: make a generic ETFObject class that these all extend so everything has this function. Will require unifying the data var on one name
     * @param string $key
     * @return mixed
     */
    public function get(string $key) {
        if (empty($key)) {
            throw new InvalidArgumentException("Key cannot be empty.");
        }

        // if no user logged in, or field genuinely does not exist, returns null
        return $this->rowData[$key];
    }

    // these flows are shit but help illustrate the cross-track drifting I want to achieve
    // API user flow (external): Authenticate->login with uname + pwrd -> token/session created -> login by id
    // legacy user flow (internal/current site): si.php with uname + pwrd (will be cs only when the api is actually in use) -> session created + cookie -> logged in
    public function login(string $email, string $password) {
        // quick sanity checks. Excluding from the ActionAttempts stuff since those checks would be more expensive than simply sending a regular failure response
        if (empty($email) || empty($password)) {
            return false;
        }

        // get IP address for ActionAttempt checks
        $ip = Core::getSessionIp();

        if (ActionAttempts::hasMetAttemptLimit("FailedLoginPassword", $ip, null)) {
            throw new RateLimitException("You have surpassed the attempt limit for login. Please wait 30 minutes and try again.");
        }

        // check if email exists and hashed password matches
        $query = Database::prepare("
            SELECT
                user_id,
                user_p
            FROM users
            WHERE user_email = :userEmail
        ");

        $query->bindValue(":userEmail", $email);
        $result = $query->executeQuery();

        if (false === $result = $result->fetchAssociative()) {
            // log this attempt with just IP since there is no user matching the provided email
            ActionAttempts::insertActionAttemptRecord("FailedLoginPassword", $ip, null);

            throw new UserNotFoundException("No existing user found for the provided email address.");
        }

        if (!password_verify($password, $result["user_p"])) {
            // log unsuccessful attempt and indicate failure
            ActionAttempts::insertActionAttemptRecord("FailedLoginPassword", $ip, $result["user_id"]);

            throw new InvalidPasswordException("Incorrect username/email or password");
        }

        // save successful login
        $this->loginWithId($result["user_id"]);
        $this->saveSuccessfulLogin($ip);

        return true;
    }

    public function loginWithRecoveryCode(string $email, int $code) {
        // quick sanity checks. Excluding from the ActionAttempts stuff since those checks would be more expensive than simply sending a regular failure response
        if (empty($email) || empty($code)) {
            return false;
        }

        // get IP address for ActionAttempt checks
        $ip = Core::getSessionIp();

        if (ActionAttempts::hasMetAttemptLimit("FailedLoginCode", $ip, null)) {
            throw new RateLimitException("You have surpassed the attempt limit for login. Please wait 30 minutes and try again.");
        }

        $query = Database::prepare("
            SELECT
                user_id,
                login_code
            FROM user_login_codes
            INNER JOIN users USING(user_id)
            WHERE
                user_email = :email AND
                expiration >= UNIX_TIMESTAMP()
        ");

        $query->bindValue(":email", $email);
        $result = $query->executeQuery();

        if (false === $result = $result->fetchAssociative()) {
            // log this attempt with just IP since there is no user matching the provided email
            ActionAttempts::insertActionAttemptRecord("FailedLoginCode", $ip, null);

            // there might be an existing user, but there for sure was not a login code record for them
            // TODO: consider using a different exception type? (probably overthinking it, per usual)
            throw new UserNotFoundException("No existing user found for the provided email address.");
        }

        if ($result['login_code'] !== $code) {
            // log unsuccessful attempt and indicate failure
            ActionAttempts::insertActionAttemptRecord("FailedLoginCode", $ip, $result["user_id"]);

            return false;
        }

        // save successful login
        $this->loginWithId($result["user_id"]);
        $this->saveSuccessfulLogin();

        return true;
    }

    /**
     * Im not a very big fan of this, but succesful login stats are useful. Keeping this table primarily for that
     * @return void
     */
    public function saveSuccessfulLogin(?string $ip = null) {
        if ($ip === null) {
            $ip = Core::getSessionIp();
        }

        $this->updateLastLogin($ip);

        $query = Database::prepare("
            INSERT INTO logins SET
                li_user_id = :userId,
                li_ip = :ip,
                li_browser = :browser,
                li_timestamp = UNIX_TIMESTAMP(),
                li_status = 'Success',
                li_errors = ''
        ");

        $query->bindValue(":userId", $this->id, ParameterType::INTEGER);
        $query->bindValue(":ip", $ip);
        $query->bindValue(":browser", $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
        $result = $query->executeQuery();
    }

    /**
     * checks if a user is currently logged in. Session data is verified/updated if they are
     * Not to be used by API flows
     * @return bool
     */
    public function loggedIn() {
        if (isset($_SESSION['user_id'])) {
            if (!isset($this->id)) {
                // ensure the id exists
                if (false === $userData = Users::getUserById($_SESSION['user_id'])) {
                    // user no longer exists or was erroneously logged in. silently reset session and treat as though nobody is logged in
                    unset($_SESSION['user_id']);
                    return false;
                }

                $this->id = $_SESSION['user_id'];
                $this->rowData = $userData;
            }

            return true;
        }

        return false;
    }

    public function logout() {
        // log event before logging out so we still have the current user email in context
        Core::dispatchAsyncProcess(Tracking::class, "sendEventToTrackers", 2, functionArgs: ['logout', null, null, null, $this->rowData]);
        
        unset($_SESSION['user_id']);
        session_destroy();
    }

    public function updateLastLogin(?string $ip = null) {
        // if a user is not logged in, dont do anything
        if (null === $this->id) {
            return;
        }

        $stmt = Database::prepare("
            UPDATE users
            SET user_last_login = :time, user_login_ip = :ip
            WHERE user_id = :id
        ");

        $stmt->bindValue(":time", time(), ParameterType::INTEGER);
        $stmt->bindValue(":ip", $ip);
        $stmt->bindValue(":id", $this->id, ParameterType::INTEGER);

        $result = $stmt->executeQuery();
    }

    /**
     * This feels like needless writes to me, but maybe it will come in handy
     * @return void
     */
    public function updateLastActivity() {
        // if a user is not logged in, dont do anything
        if (null === $this->id) {
            return;
        }

        $stmt = Database::prepare("
            UPDATE users
            SET user_last_activity = :time
            WHERE user_id = :id
        ");

        $stmt->bindValue(":time", time(), ParameterType::INTEGER);
        $stmt->bindValue(":id", $this->id, ParameterType::INTEGER);

        $result = $stmt->executeQuery();
    }

    /**
     * Replacement function for users->change_password but significantly less ass
     * @param string $code
     * @param string $newPassword
     * @throws \InvalidArgumentException
     * @throws \PHPerformance\Exceptions\RateLimitException
     * @return bool
     */
    public function changePassword(string $code, string $newPassword): bool
    {
        $ip = Core::getSessionIp();

        if (ActionAttempts::hasMetAttemptLimit('UpdatePassword', $ip, $this->id)) {
            throw new RateLimitException('You have too many password update attempts. Please wait 30 minutes and try again.');
        }

        ActionAttempts::insertActionAttemptRecord("UpdatePassword", $ip, $this->id);

        // verifies that the code exists and is valid while also deleting it
        $stmt = Database::prepare("
            DELETE FROM user_login_codes
            WHERE
                user_id = :userId AND
                login_code = :code AND
                expiration > UNIX_TIMESTAMP()
        ");

        $stmt->bindValue(":userId", $this->id, ParameterType::INTEGER);
        $stmt->bindValue(":code", $code);
        $result = $stmt->executeStatement();

        if ($result !== 1) {
            throw new AuthenticationException("Invalid or expired code. Please request a new one.");
        }

        // commit password change
        $stmt = Database::prepare("
            UPDATE users
            SET user_p = :encrypted
            WHERE user_id = :userId
        ");

        $stmt->bindValue(":encrypted", Users::hashPassword($newPassword));
        $stmt->bindValue(":userId", $this->id, ParameterType::INTEGER);
        $result = $stmt->executeStatement();

        if ($result !== 1) {
            // at this point, the user code has been confirmed to be valid and the password should have been updated. if we hit this condition, something has gone wrong so log
            throw new SystemException("Failed to update password for user " . $this->id, "PasswordUpdateFailed");
        }

        return true;
    }

    /**
     * Single replacement function for all the individual level check functions. Intended for use with the USER_LEVEL constants in the Users class
     * @param int $userLevel
     * @return bool
     */
    public function hasPermissions(int $userLevel): bool
    {
        // if a user is not logged in, dont do anything
        if (null === $this->id) {
            return false;
        }

        return $this->rowData['user_level'] >= $userLevel;
    }

    public function isReadOnly(): bool
    {
        // if a user is not logged in, dont do anything
        if (null === $this->id) {
            return false;
        }

        return $this->rowData['user_readonly'] === 1;
    }

    public function isBanned(): bool
    {
        // if a user is not logged in, dont do anything
        if (null === $this->id) {
            return false;
        }

        return $this->rowData['user_banned'] === 1;
    }

    /**
     * @return bool true if the user is banned from making purchases, false otherwise
     */
    public function isPurchaseBanned(): bool
    {
        if (null === $this->id) {
            return false;
        }

        if ($this->rowData['user_country'] === '' || $this->rowData['user_country'] === null) {
            return true;
        }

        if ($this->rowData['user_purchase_ban'] === 1) {
            return true;
        }

        $stmt = Database::prepare(<<<SQL
            SELECT is_restricted
            FROM countries
            WHERE (iso2 = :countryCode OR iso3 = :countryCode)        
        SQL);
        $stmt->bindValue(":countryCode", $this->rowData['user_country']);
        $result = $stmt->executeQuery()->fetchAssociative();
        return $result['is_restricted'] === 1;
    }

    /**
     * UserInterface mandatory functions
     */
    public function getRoles(): array
    {
        return [self::mapLevelToRole($this->rowData['user_level'])];
    }

    private static function mapLevelToRole(int $level): string
    {
        return match($level) {
            Users::USER_LEVEL_COUPON_ACCESS => 'ROLE_COUPON',
            Users::USER_LEVEL_MARKETING_ADMIN => 'ROLE_MARKETING_ADMIN',
            Users::USER_LEVEL_CUSTOMER_SERVICE => 'ROLE_CUSTOMER_SERVICE',
            Users::USER_LEVEL_FINANCE_ADMIN => 'ROLE_FINANCE_ADMIN',
            Users::USER_LEVEL_GLOBAL_ADMIN => 'ROLE_ADMIN',
            default => 'ROLE_USER',
        };
    }

    
    public function getUserIdentifier(): string
    {
        return $this->rowData['user_id'];
    }
    
    public function getPassword(): string
    {
        return $this->rowData['user_p'];
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // this is deprecated but due to UserInterface, must be implemented
        // https://symfony.com/blog/new-in-symfony-7-3-security-improvements#deprecate-erasecredentials-method
    }

    /**
     * Check if the current user has ever enabled push notifications in the past
     * @return bool
     */
    public function hasEnabledPushNotifications(): bool
    {
        return $this->rowData['user_activated_push_notifications'] === 1;
    }
}