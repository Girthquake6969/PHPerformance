<?php
//PURGE FROM THIS CODEBASE BUT USE AS EXAMPLE
namespace App\Model;

use PHPerformance\Core\Core;
use PHPerformance\Exceptions\RateLimitException;
use PHPerformance\Exceptions\SystemException;
use Exception;
use InvalidArgumentException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Exception\DriverException;
use PHPerformance\Objects\User;
use PHPerformance\Core\Database;
use PHPerformance\Logging;
use PHPerformance\Objects\Product;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use PHPerformance\API\Stripe;

/**
 * A class for query functions on the users table
 * Also keeps a user object in static context so no more class_exists checks
 */
enum UserTableColumn: int
{
    case EMAIL = 4;
    case USERNAME = 5;
    case FULL_NAME = 6;
    case COUNTRY = 7;
    case STATE = 8;
    case CITY = 9;
    case ZIPCODE = 10;
    case RITHMIC_STATUS = 11;

    public function getColumnName(): string
    {
        return match ($this) {
            self::EMAIL => 'user_email',
            self::USERNAME => 'username',
            self::FULL_NAME => 'CONCAT(user_fname, " ", user_lname)',
            self::COUNTRY => 'user_country',
            self::STATE => 'user_state',
            self::CITY => 'user_city',
            self::ZIPCODE => 'user_zipcode',
            self::RITHMIC_STATUS => 'user_rithmic_status',
        };
    }
}
class Users
{
    public static User $currentUser;

    public const USER_LEVEL_COUPON_ACCESS = 2;
    public const USER_LEVEL_MARKETING_ADMIN = 4;
    public const USER_LEVEL_CUSTOMER_SERVICE = 5;
    public const USER_LEVEL_FINANCE_ADMIN = 6;
    public const USER_LEVEL_GLOBAL_ADMIN = 10;
    public const ETF_AUTOMATION_USER_ID = 189860;

    /**
     * update stored user
     */
    public static function setCurrentUser(): void
    {
        self::$currentUser = User::constructDefault();
    }

    // used for API calls when the request's JWT has been verified
    public static function setCurrentUserById(int $userId): void
    {
        self::$currentUser = User::constructWithIdNoLogin($userId);
    }

    /**
     * Set empty user and skip session check
     * @return void
     */
    public static function setDefaultApiUser(): void
    {
        self::$currentUser = User::constructForApi();
    }

    /*
        QUERY FUNCTIONS
    */

    /**
     * Ban a list of users provided by array
     * TODO: CS user check (if needed)
     */
    public static function ban_users(int ...$userIDs): bool
    {
        $query = "
            UPDATE users u
            LEFT JOIN purchases p
                ON p.pur_user_id = u.user_id
                AND p.pur_processor_subscription_id IS NOT NULL
            SET
                u.user_banned = 1
            WHERE
                u.user_id IN (:placeholders)
        ";

        // will throw an error if $userIDs is empty
        Database::prepareWithInClause($query, $userIDs)->executeQuery();
        return true;
    }

    /**
     * Unban a list of users provided by array
     * TODO: CS user check (if needed)
     */
    public static function unban_users(int ...$userIDs): bool
    {
        $query = "
            UPDATE users SET
                user_banned = NULL
            WHERE
                user_id IN (:placeholders)
        ";

        // will throw an error if $userIDs is empty
        Database::prepareWithInClause($query, $userIDs)->executeQuery();

        return true;
    }

    public static function get_user_id_by_email($email)
    {
        // Prepare the SQL statement
        $stmt = Database::prepare("SELECT user_id FROM users WHERE user_email = :email");

        // Bind the email parameter to the prepared statement
        $stmt->bindValue(":email", $email, ParameterType::STRING);

        // Execute the query
        $result = $stmt->executeQuery();

        // Fetch the result
        $result = $result->fetchAssociative();

        // Return the user ID if found, otherwise return null
        return $result ? $result['user_id'] : null;
    }

    public static function getUserIdByUsername(string $username)
    {
        $stmt = Database::prepare("
            SELECT user_id FROM users
            WHERE username = :username
        ");

        $stmt->bindValue(":username", $username);
        $result = $stmt->executeQuery();

        return $result->fetchOne();
    }

    public static function getUsernameById(int $userId)
    {
        $stmt = Database::prepare("
            SELECT username FROM users
            WHERE user_id = :userId
        ");

        $stmt->bindValue(":userId", $userId);
        $result = $stmt->executeQuery();

        return $result->fetchOne();
    }

    public static function getUserById(int $userId)
    {
        $query = Database::prepare("
            SELECT * FROM users
            WHERE user_id = :userId
        ");

        $query->bindValue(":userId", $userId);
        $result = $query->executeQuery();

        return $result->fetchAssociative();
    }

    public static function getUserByEmail(string $email): array|false
    {
        $query = Database::prepare("
            SELECT * FROM users
            WHERE user_email = :email
        ");

        $query->bindValue(":email", $email);
        $result = $query->executeQuery();

        return $result->fetchAssociative();
    }

    /**
     * Get non-sensitive user data needed for the frontend (via API)
     * This does include address info to autofill on the billing page
     * @param int $userId user id
     * @return array|false associative array representing the db row retrieved or false on failure/empty set
     */
    public static function getApiFriendlyUserData(int $userId)
    {
        $query = Database::prepare(<<<SQL
            SELECT
                user_id,
                user_email,
                username,
                user_fname,
                user_lname,
                user_country,
                user_state,
                user_city,
                user_address,
                user_address2,
                user_zipcode,
                FROM_UNIXTIME(user_registration_date) as registration_date,
                user_tradovate_id,
                user_tradovate_subscription_id,
                user_tradovate_subscription_start,
                user_tradovate_agreements,
                user_rithmic_last_account,
                user_rithmic_status,
                user_rithmic_login_expiration,
                user_level,
                user_readonly,
                user_banned,
                user_banned_reason,
                user_business_name,
                user_refby_id,
                FROM_UNIXTIME(user_last_login) as last_login,
                user_allow_aff_coupon_override,
                start_timestamp AS trade_break_start,
                end_timestamp AS trade_break_end
            FROM users
            LEFT JOIN trade_breaks USING(user_id)
            WHERE user_id = :id
        SQL);

        $query->bindValue(":id", $userId, ParameterType::INTEGER);
        $result = $query->executeQuery();

        // TODO: determine if we want to format anything here or if that should be left to the calling function(s)
        return $result->fetchAssociative();
    }

    public static function registerNewUser(
        string $firstname,
        string $lastname,
        string $email,
        string $username,
        ?string $password,
        ?string $address = null,
        ?string $address2 = null,
        ?string $city = null,
        ?string $state = null,
        ?string $country = null,
        ?string $zipCode = null,
        ?string $affiliateCode = null,
        ?string $googleId = null,
        bool $registrationPending = false
    ): array {
        $response = ["success" => false, "message" => ""];

        $registrationDate = time();
        $isGoogleSignup = $googleId !== null;

        $insertQuery = "
            INSERT INTO users SET
                user_email = :email,
                username = :username,
                user_fname = :firstname,
                user_lname = :lastname,
                user_registration_date = :date,
                user_signup_ip = :signup_ip,
                user_address = :address,
                user_address2 = :address2,
                user_city = :city,
                user_state = :state,
                user_country = :country,
                user_zipcode = :zipcode,
                user_registration_pending = :registrationPending
        ";

        if ($isGoogleSignup) {
            $insertQuery .= ", user_google_id = :googleId, user_auth_provider = 'google'";
        } else {
            $hashedPassword = self::hashPassword($password);
            $insertQuery .= ", user_p = :password";
        }

        if (!empty($affiliateCode) && !$isGoogleSignup) {
            // map the affiliate code to a user id for entry
            $affiliateUserId = Users::getUserIdByUsername($affiliateCode);

            if (true === $haveValidAffiliate = $affiliateUserId !== false) {
                $insertQuery .= ", user_refby_id = :affiliateUserId";
            }
        }

        // normalize state entry to iso2 (if possible); Google signups have no
        // address yet, so state/country are null — skip normalization (the
        // function requires non-null strings) and leave the nullable column null.
        if ($state !== null && $country !== null) {
            $state = self::normalizeStateInput($state, $country);
        }

        $statement = Database::prepare($insertQuery);

        $statement->bindValue(':email', $email);
        $statement->bindValue(':username', $username);
        $statement->bindValue(':firstname', $firstname);
        $statement->bindValue(':lastname', $lastname);
        $statement->bindValue(':date', $registrationDate);
        $statement->bindValue(':signup_ip', Core::getSessionIp());
        $statement->bindValue(':address', $address);
        $statement->bindValue(':address2', $address2 ?? '');
        $statement->bindValue(':city', $city);
        $statement->bindValue(':state', $state);
        $statement->bindValue(':country', $country);
        $statement->bindValue(':zipcode', $zipCode);
        $statement->bindValue(':registrationPending', $registrationPending ? 1 : 0, ParameterType::INTEGER);

        if ($isGoogleSignup) {
            $statement->bindValue(':googleId', $googleId);
        } else {
            $statement->bindValue(':password', $hashedPassword);
        }

        if (!empty($haveValidAffiliate)) {
            $statement->bindValue(':affiliateUserId', $affiliateUserId, ParameterType::INTEGER);
        }

        try {
            // execute may throw an error
            if (false === $statement->executeQuery()) {
                $response['message'] = ['There was an unknown error when inserting your user record.'];
                return $response;
            }

            // is an auto increment primary key, so will always be able to cast to int
            $userId = (int) Database::lastInsertId();
        } catch (DriverException $e) {
            // TODO: create function to handle all common MySQL exceptions and send curated response messages
            // for now, see if this is a duplicate constraint and determine if it is username or email
            $errorMessage = $e->getMessage();

            if (false === strpos($errorMessage, "Duplicate entry")) {
                // this is not an error we want to show the user. Throw it for the upstream handler
                throw new SystemException($errorMessage);
            }

            if (false !== strpos($errorMessage, "user_email") || false !== strpos($errorMessage, "user_google_id")) {
                // Provider-agnostic on purpose: don't reveal whether the existing account is
                // email/password or Google (account-enumeration). The sign-in page offers both.
                $response['message'] = ["An account with this email already exists. Please sign in to continue."];
            } else {
                $response['message'] = ["The provided username '$username' is already in use."];
            }

            return $response;
        }

        // insert default affiliate record
        Affiliates::insertAffiliate($userId);

        $similarUsers = self::getSimilarUsers($userId);

        // I chose 50 because that is the weight for signup_ip. If that's similar, then it should get flagged
        // similarly, if the entire address together gets flagged, that score is > 50
        // if only the country, state, city, and zip are flagged, those weights add up to 45
        $topSimilarUser = reset($similarUsers);
        if (!empty($topSimilarUser)) {
            // Google OAuth signups go to manual review only — never auto-ban to avoid false-positives
            if (!IS_DEV && !$isGoogleSignup) {
                if ($topSimilarUser['score'] >= 50) {
                    Users::ban_users($userId);

                    $response["message"] = ["The information provided for signup matches an existing user. Creating multiple users is against our terms of service, so this account has been banned. If you believe this to be an error, please contact customer service."];
                    return $response;
                }
            }

            // Add to watchlist if score >= 45
            if ($topSimilarUser['score'] >= 45) {
                $watchlist = Watchlists::getWatchlistByName("Potential Duplicate User");

                if (!empty($watchlist)) {
                    Watchlists::addUserToWatchlist($watchlist['wl_id'], $userId);

                    Logging::sendLogToSumoLogic("Potential Duplicate User" . ($isGoogleSignup ? " (Google signup)" : ""), [
                        'newUserSignupId' => $userId,
                        'topSimilarUserId' => key($similarUsers),
                    ]);
                }
            }
        }

        // add id to response, mark success, and return
        $response['userId'] = $userId;
        $response['success'] = true;

        return $response;
    }

    /**
     * Capture a brand-new Google user the moment their identity is verified, before
     * they finish the registration form. Stores email + name + Google id with
     * user_registration_pending = 1 so an abandoned signup still leaves us their
     * details for marketing follow-up. Reuses registerNewUser for the actual insert
     * (affiliate record, duplicate detection, etc.); a unique username is generated
     * since the user hasn't chosen one yet — they set their own on completion.
     * The insert has no address, so the account reads as "partial" until one is saved.
     */
    public static function registerPartialGoogleUser(
        string $email,
        string $googleId,
        ?string $firstName,
        ?string $lastName
    ): array {
        return self::registerNewUser(
            $firstName ?? '',
            $lastName ?? '',
            $email,
            self::generateUniqueUsernameFromEmail($email),
            null,
            googleId: $googleId,
            registrationPending: true
        );
    }

    /**
     * Finish a captured Google lead once the user submits the registration form:
     * apply their (possibly edited) name + chosen username and clear the registration-pending
     * flag, which turns the captured lead into a fully registered, sign-in-able account.
     * The account stays "partial" (no address touched here — that's derived from the address
     * columns), so the user goes straight to the dashboard and address is forced only at first
     * purchase. The user_registration_pending = 1 guard makes this a no-op on an already-completed
     * account (idempotent for double-submits). Username collisions surface a clear error.
     */
    public static function completeGoogleRegistration(
        int $userId,
        string $firstName,
        string $lastName,
        string $username
    ): array {
        $statement = Database::prepare("
            UPDATE users SET
                user_fname = :firstname,
                user_lname = :lastname,
                username = :username,
                user_registration_pending = 0
            WHERE user_id = :userId AND user_registration_pending = 1
        ");

        $statement->bindValue(':firstname', $firstName);
        $statement->bindValue(':lastname', $lastName);
        $statement->bindValue(':username', $username);
        $statement->bindValue(':userId', $userId, ParameterType::INTEGER);

        try {
            $statement->executeStatement();
        } catch (DriverException $e) {
            if (false !== strpos($e->getMessage(), "Duplicate entry")) {
                return ["success" => false, "message" => ["The provided username '$username' is already in use."]];
            }
            throw new SystemException($e->getMessage());
        }

        return ["success" => true];
    }

    /**
     * Build a unique username from a Google email's local-part. The username column is
     * UNIQUE, so we probe with getUserIdByUsername and append a numeric suffix until a
     * free one is found; the INSERT's unique index is the ultimate guard against races.
     */
    private static function generateUniqueUsernameFromEmail(string $email): string
    {
        $base = self::generateUsernameFromEmail($email);

        if (false === self::getUserIdByUsername($base)) {
            return $base;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = substr($base, 0, 28) . random_int(100, 999);
            if (false === self::getUserIdByUsername($candidate)) {
                return $candidate;
            }
        }

        // Exhausted tidy suffixes; fall back to a wider random tail (still <= 32 chars).
        return substr($base, 0, 25) . random_int(1000000, 9999999);
    }

    /**
     * Derive a schema-valid username base (alphanumeric, >= 5 chars) from an email's
     * local-part, padding with digits when the local-part is too short.
     */
    private static function generateUsernameFromEmail(string $email): string
    {
        $local = strstr($email, '@', true) ?: $email;
        $base = preg_replace('/[^a-zA-Z0-9]/', '', $local) ?? '';
        $base = substr($base, 0, 24);

        if (strlen($base) < 5) {
            $base .= str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        }

        return $base;
    }

    /**
     * Attach a verified Google identity to an existing account.
     * @return bool true if exactly one row was updated
     */
    public static function linkGoogleAccount(int $userId, string $googleId): bool
    {
        $query = Database::prepare("
            UPDATE users SET user_google_id = :googleId, user_auth_provider = 'google'
            WHERE user_id = :userId AND user_google_id IS NULL
        ");

        $query->bindValue(":googleId", $googleId);
        $query->bindValue(":userId", $userId, ParameterType::INTEGER);

        try {
            return $query->executeStatement() === 1;
        } catch (DriverException $e) {
            // unique constraint: this Google id is already linked to a different account
            return false;
        }
    }

    /**
     * Add or update a user's billing address in their user record
     * @param int $userId - the user's user_id pertaining to their user record
     * @param string $address - line 1 of a standard address
     * @param string $city - full city name
     * @param string $state - state name or abbreviation TODO: lock this down to one or the other
     * @param string $country - country abbreviation from our list TODO: properly verify this
     * @param string $zipcode - partial or full zipcode
     * @param string $address2 - optional second address line. Default "". EX: "Apt 101"
     */
    public static function addBillingAddress(int $userId, string $address, string $city, string $state, string $country, string $zipcode, string $address2 = ""): bool
    {
        // verify all necessary address info is present
        if (empty($address) || empty($city) || empty($state) || empty($country) || empty($zipcode)) {
            throw new InvalidArgumentException("Cannot update billing address with an incomplete address.");
        }

        // A complete address is now on file (validated above), so the profile is no longer
        // partial — "partial" is derived from these address columns at read time
        // (see UserFormatter), so saving a full address here flips it implicitly.
        $query = Database::prepare("
            UPDATE users SET
                user_address = :address,
                user_address2 = :address2,
                user_city = :city,
                user_state = :state,
                user_country = :country,
                user_zipcode = :zipcode
            WHERE
                user_id = :userId
        ");

        $query->bindValue(":address", $address);
        $query->bindValue(":address2", $address2);
        $query->bindValue(":city", $city);
        $query->bindValue(":state", $state);
        $query->bindValue(":country", $country);
        $query->bindValue(":zipcode", $zipcode);
        $query->bindValue(":userId", $userId, ParameterType::INTEGER);

        $result = $query->executeQuery();

        // we query by primary key, so this will be 1 if the user exists and the update was successful, or 0 if not
        return $result->rowCount() === 1;
    }

    /**
     * Update a user's legal name (first/last). Used alongside addBillingAddress to
     * let a user correct their name in the same set-once profile-completion step
     * before their first purchase. Intentionally does not gate on rowCount: an
     * unchanged name (the common case when the prefilled value is kept) affects 0
     * rows yet is a valid no-op.
     */
    public static function updateName(int $userId, string $firstName, string $lastName): void
    {
        $query = Database::prepare("
            UPDATE users SET
                user_fname = :firstName,
                user_lname = :lastName
            WHERE
                user_id = :userId
        ");

        $query->bindValue(":firstName", $firstName);
        $query->bindValue(":lastName", $lastName);
        $query->bindValue(":userId", $userId, ParameterType::INTEGER);

        $query->executeQuery();
    }

    public static function deleteUser(int $userId): bool
    {
        $query = Database::prepare("DELETE FROM USERS WHERE user_id = :id");
        $query->bindValue(":id", $userId);

        try {
            $result = $query->executeQuery();
        } catch (DriverException $e) {
            // likely a foreign key constraint causing failure
            // TODO: instead of simply returning false, run a function that clears user data from user with important historical data so we still have it but the account is generic
            return false;
        }

        return $result->rowCount() === 1;
    }

    /**
     * Ensure a provided email address is valid
     * TODO: consider moving to a core functionality class since this is not only used here
     */
    public static function isValidEmail(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) == false) {
            return false;
        }

        [$local, $domain] = explode('@', $email, 2);
        return preg_match('/^[a-zA-Z0-9.\-_+]*$/', $local) === 1;
    }

    /**
     * Links a user's platform credentials. Handles encryption and storage for all platforms.
     * 
     * @param int $userId our internal user ID
     * @param string $platform platform identifier ('rithmic', 'tradovate', 'tickblaze')
     * @param string $username platform login username
     * @param string $password plaintext password to encrypt and store
     * @param int|null $platformUserId platform-side user ID, if applicable
     */
    public static function linkPlatformLogin(int $userId, string $platform, string $username, string $password, ?int $platformUserId = null): void
    {
        if (empty($username)) {
            throw new InvalidArgumentException('Platform login username cannot be empty');
        }

        if (strlen($password) < Core::PASSWORD_MIN_LENGTH) {
            throw new InvalidArgumentException('Supplied password is below the minimum allowed length');
        }

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encryptedPassword = urlencode(openssl_encrypt($password, 'aes-256-cbc', SALT, OPENSSL_RAW_DATA, $iv) . ':' . base64_encode($iv));

        Logging::sendLogToSumoLogic("PlatformCredentialCreated", [
            'userId' => $userId,
            'platform' => $platform,
            'platformUserId' => $platformUserId,
            'username' => $username,
            'password' => $encryptedPassword,
        ], true);

        $platformUserIdColumn = match ($platform) {
            'tradovate' => 'user_tradovate_id',
            'tickblaze' => 'user_tickblaze_id',
            default => null,
        };

        // todo: rather than have these user_platform_id fields, add a new field in platform_credentials that contains that data
        if ($platformUserIdColumn !== null && $platformUserId !== null) {
            $stmt = Database::prepare("UPDATE users SET $platformUserIdColumn = :platformUserId WHERE user_id = :userId");
            $stmt->bindValue(':platformUserId', $platformUserId, ParameterType::INTEGER);
            $stmt->bindValue(':userId', $userId, ParameterType::INTEGER);
            $stmt->executeQuery();
        }

        $query = Database::prepare(<<<SQL
            INSERT INTO platform_credentials (user_id, platform, username, password) 
            VALUES (:userId, :platform, :username, :encrypted)
            ON DUPLICATE KEY UPDATE 
                username = VALUES(username),
                password = VALUES(password) 
        SQL);

        $query->bindValue(':userId', $userId, ParameterType::INTEGER);
        $query->bindValue(':platform', $platform);
        $query->bindValue(':username', $username);
        $query->bindValue(':encrypted', $encryptedPassword);
        $query->executeQuery();
    }

    /**
     * Retrieves the ban status for the specified user.
     *
     * @param int $userId The ID of the user to retrieve the ban status for.
     * @return array An associative array containing the user's ban status, with the following keys:
     *               - `user_banned`: A boolean indicating whether the user is banned.
     *               - `user_purchase_ban`: A boolean indicating whether the user is banned from making purchases.
     *               - `user_banned_reason`: The reason for the user's ban, or `null` if the user is not banned.
     *               - `user_dispute_whitelist`: A boolean indicating whether the user is on the dispute whitelist.
     */
    public static function getUserBanStatus(int $userId): array
    {
        $stmt = Database::prepare("
            SELECT
                users.user_banned,
                users.user_purchase_ban,
                users.user_banned_reason,
                users.user_dispute_whitelist,
                affiliates.aff_banned
            FROM users
            LEFT JOIN affiliates ON affiliates.aff_user_id = users.user_id
            WHERE users.user_id = :user_id
        ");
        $stmt->bindValue(':user_id', $userId, ParameterType::INTEGER);
        $result = $stmt->executeQuery();

        $row = $result->fetchAssociative();

        if ($row === false) {
            return [
                'user_banned'            => false,
                'user_purchase_ban'      => false,
                'user_banned_reason'     => null,
                'user_dispute_whitelist' => false,
                'aff_banned'             => false,
            ];
        }

        return [
            'user_banned'            => ($row['user_banned'] === 1),
            'user_purchase_ban'      => ($row['user_purchase_ban'] === 1),
            'user_banned_reason'     => $row['user_banned_reason'] ?? null,
            'user_dispute_whitelist' => ($row['user_dispute_whitelist'] === 1),
            'aff_banned'             => ($row['aff_banned'] === 1),
        ];
    }

    /**
     * Batch retrieves ban status for multiple users in a single query.
     *
     * @param array $userIds Array of user IDs to retrieve ban status for
     * @return array Associative array keyed by user_id, each containing:
     *               - `user_banned`: bool
     *               - `user_purchase_ban`: bool
     *               - `user_banned_reason`: string|null
     *               - `user_dispute_whitelist`: bool
     */
    public static function getBatchUserBanStatus(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $stmt = Database::prepareWithInClause("
            SELECT
                users.user_id,
                users.user_banned,
                users.user_purchase_ban,
                users.user_banned_reason,
                users.user_dispute_whitelist,
                affiliates.aff_banned
            FROM users
            LEFT JOIN affiliates ON affiliates.aff_user_id = users.user_id
            WHERE users.user_id IN (:placeholders)
        ", $userIds);

        $result = $stmt->executeQuery();
        $banStatuses = [];

        while ($row = $result->fetchAssociative()) {
            $banStatuses[$row['user_id']] = [
                'user_banned'            => ($row['user_banned'] === 1),
                'user_purchase_ban'      => ($row['user_purchase_ban'] === 1),
                'user_banned_reason'     => $row['user_banned_reason'] ?? null,
                'user_dispute_whitelist' => ($row['user_dispute_whitelist'] === 1),
                'aff_banned'             => ($row['aff_banned'] === 1),
            ];
        }

        // Ensure all requested user IDs have entries (default to not banned)
        foreach ($userIds as $userId) {
            if (!isset($banStatuses[$userId])) {
                $banStatuses[$userId] = [
                    'user_banned'            => false,
                    'user_purchase_ban'      => false,
                    'user_banned_reason'     => null,
                    'user_dispute_whitelist' => false,
                    'aff_banned'             => false,
                ];
            }
        }

        return $banStatuses;
    }

    /**
     * Replacement for user::decrypt_rithmic_pw
     * Im not too sure why this password format was selected, or if it is effective in its security, but we will continue to use for now
     * @param string $password encrypted string to decrypt
     * @return string|false decrypted string or false on failure
     */
    public static function decryptPlatformPassword(?string $password)
    {
        // for compatibility for now since some of the email functions try to decrypt both passwords without checking if they exist. Will remove this once that has been nuked from orbit
        if (empty($password)) {
            return '';
        }

        // the old function split the string by ':' and then looped through to reconnect all but the string after the last instance of ':'.
        // we can instead take advantage of a regular expression to only split by the last one
        // note that the actual pattern here is :(?=[^:]*$) and the /'s are simply needed delimeters, even though we arent using any regex flags
        // if you want to see how this pattern works, with full breakdown, plug it in on regex101.com and test with string one:two:three:four
        if (false === $split = preg_split('/:(?=[^:]*$)/', urldecode($password))) {
            throw new InvalidArgumentException('Invalid or empty string passed for decryption');
        }

        // preg_split returns false on failure, but will still return an array with one element if there are no matches. Kinda wish both returned false...
        if (count($split) !== 2) {
            throw new InvalidArgumentException('Provided string for decryption is malformed');
        }

        return openssl_decrypt($split[0], 'aes-256-cbc', SALT, OPENSSL_RAW_DATA, base64_decode($split[1]));
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function isValidPassword(string $password): bool
    {
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[\*\!\@\#\$\%\^\&\(\)\-\_\<\>\?\;\:\=]/', $password) || strlen($password) < 8) {
            return false;
        }

        return true;
    }


    /*PRIVATE HELPER FUNCTIONS*/

    private static function isValidUsername(string $username): bool
    {
        if (!ctype_alnum($username) || strlen($username) < 5) {
            return false;
        }

        return true;
    }

    /**
     * Retrieves the user's purchase ban status.
     *
     * @param int $userId The ID of the user.
     * @return string|null The user's purchase ban status, or null if not found.
     */
    public static function get_user_purchase_ban(int $userId)
    {
        // Prepare the SQL statement
        $stmt = Database::prepare("SELECT user_purchase_ban FROM users WHERE user_id = :user_id");

        // Bind the email parameter to the prepared statement
        $stmt->bindValue(":user_id", $userId, ParameterType::STRING);

        // Execute the query
        $result = $stmt->executeQuery();

        // Fetch the result
        $result = $result->fetchAssociative();

        // Return the user_purchase_ban value if found, otherwise return null
        return $result ? $result['user_purchase_ban'] : null;
    }

    /**
     * Retrieve a list of id, email, and country name for users residing in the provided country (by full name, not code)
     * @param string $country - name of the country (first letter capitalized) EX: 'France'
     */
    public static function getActiveUsersByCountry(string $country): array
    {
        $query = Database::prepare("
            SELECT DISTINCT
                user_id,
                user_email,
                name
            FROM
                (users,
                 countries)
            INNER JOIN
                rithmic_accounts ON ra_user_id = user_id
            WHERE
                ra_status = 'Active' AND
                user_country = iso2 AND
                name = :country
        ");

        $query->bindValue(":country", $country);
        $result = $query->executeQuery();

        return $result->fetchAllAssociative();
    }

    public static function userCanPayWithPoints(int $userId, Product $product, ?int $customPrice = null): bool
    {
        if (!$product->canPayWithPoints()) {
            throw new InvalidArgumentException("Provided product not eligible for points payment");
        }

        $userPointStats = Points::getPointsBalancesForUser($userId);
        $pointsCost = Points::getPointsCostForProduct($product->getRowData(), customPrice: $customPrice);

        return $userPointStats["available"] >= $pointsCost;
    }

    // TODO: verify email is actually an email address
    /**
     * Generate a code for login OR password reset
     * NOTE: since password change requires the user to be logged in, The check on User->loggedIn has been removed. That should instead be checked when attempting to use the code
     * @param string $email
     * @throws \InvalidArgumentException
     * @throws \PHPerformance\Exceptions\RateLimitException
     * @throws \PHPerformance\Exceptions\SystemException
     * @return int
     */
    public static function generateLoginCode(string $email): int
    {
        if (empty($email)) {
            throw new InvalidArgumentException('Attempting to login with an empty email address');
        }

        if (null === $userId = Users::get_user_id_by_email($email)) {
            throw new InvalidArgumentException('Attempting to login with an email address that is not associated with any user');
        }

        if (ActionAttempts::hasMetAttemptLimit("LoginCodeRequest", Core::getSessionIp(), $userId)) {
            throw new RateLimitException('You have maxed out your requests for login codes. Please try again in 30 minutes');
        }

        $code = rand(100000, 999999);
        $expiration = strtotime('+5 minute');

        // use an insert with an ON DUPLICATE UPDATE clause
        $query = Database::prepare("
            INSERT INTO user_login_codes (user_id, login_code, expiration)
            VALUES(:userId, :code, :expiration)
            ON DUPLICATE KEY UPDATE
                login_code = VALUES(login_code),
                expiration = VALUES(expiration)
        ");

        $query->bindValue(":userId", $userId, ParameterType::INTEGER);
        $query->bindValue(":code", $code, ParameterType::INTEGER);
        $query->bindValue(":expiration", $expiration, ParameterType::INTEGER);

        if (!$result = $query->executeQuery()) {
            throw new SystemException("Failed inserting a login code for user $userId");
        }

        ActionAttempts::insertActionAttemptRecord("loginCodeRequest", Core::getSessionIp(), $userId);

        return $code;
    }

    /**
     * Verify a login code for a user by email and code. Throws an exception if the code is invalid or expired.
     *
     * @param string $email The user's email address.
     * @param int    $code  The login code to verify.
     *
     * @return bool Always returns true.
     *
     * @throws AuthenticationException If the provided code is invalid or expired.
     */
    public static function verifyResetCode(string $email, int $code): bool
    {
        if (false === $user = Users::getUserByEmail($email)) {
            // message to show to user. Intentionally cryptic for security
            throw new AuthenticationException('Invalid or expired code. Please request a new one.');
        }

        $stmt = Database::prepare("
            SELECT login_code FROM user_login_codes
            WHERE
                user_id = :userId AND
                login_code = :code AND
                expiration > UNIX_TIMESTAMP()
        ");

        $stmt->bindValue(":userId", $user['user_id'], ParameterType::INTEGER);
        $stmt->bindValue(":code", $code);
        $result = $stmt->executeQuery();

        $code = $result->fetchOne();

        if (false === $code) {
            throw new AuthenticationException('Invalid or expired code. Please request a new one.');
        }

        return true;
    }

    /**
     * Direct static replacement of user->search_user
     * Slow inefficient query to search a term on a lot of different fields. Used on the CS pages
     * TODO: figure out what people actually exclusively search for and limit the where clause
     * @param string $searchTerm
     * @return array
     */
    public static function searchUsers(string $searchTerm, string $colFilter = ''): array
    {
        $stmt = Database::prepare("
            SELECT users.*, 
                COUNT(rithmic_accounts.ra_id) AS total_accounts,
                (SELECT COUNT(*)
                    FROM rithmic_accounts
                    WHERE ra_user_id = users.user_id
                    AND ra_status = 'Active'
                    AND ra_rithmic_status = 'Active'
                    AND ra_elite = 0) AS active_evals,
                (SELECT COUNT(*)
                    FROM rithmic_accounts
                    WHERE ra_user_id = users.user_id
                    AND ra_status = 'Active'
                    AND ra_rithmic_status = 'Active'
                    AND ra_elite = 1) AS active_elites
            FROM users 
            LEFT JOIN rithmic_accounts ON users.user_id = rithmic_accounts.ra_user_id
            " . self::getMultiFieldSearchWhereClause() . "
            GROUP BY user_id
            ORDER BY user_id desc
        ");

        $stmt->bindValue(":searchTerm", '%' . $searchTerm . '%', ParameterType::STRING);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * A direct static replacement for user->get_users_pagination
     * @param int $offset
     * @param int $limit
     * @param mixed $searchTerm
     * @return array
     */
    public static function getUsersPagination(int $offset = 0, int $limit = 0, string $searchTerm = null, int $colFilter = null): array
    {
        $whereStmt = '';
        $params = [];

        if ($colFilter && $searchTerm) {
            $whereStmt .= ' WHERE ' . UserTableColumn::from($colFilter)->getColumnName() . ' LIKE :searchTerm';
            $params['searchTerm'] = '%' . $searchTerm . '%';
        } else {
            if (!$colFilter && $searchTerm) {
                $whereStmt = self::getMultiFieldSearchWhereClause();

                $params['searchTerm'] = '%' . $searchTerm . '%';
            }
        }

        if ($limit > 0) {
            $limitStmt = ' LIMIT :limit OFFSET :offset';
            $params['limit'] = (int)$limit;
            $params['offset'] = (int)$offset;
        } else {
            $limitStmt = '';
        }

        $stmt = Database::prepare("
            SELECT
                users.*,
                affiliates.*, 
                COUNT(rithmic_accounts.ra_id) AS total_accounts,
                (SELECT COUNT(*)
                    FROM rithmic_accounts
                    WHERE ra_user_id = users.user_id
                    AND ra_status = 'Active'
                    AND ra_rithmic_status = 'Active'
                    AND ra_elite = 0) AS active_evals,
                (SELECT COUNT(*)
                    FROM rithmic_accounts
                    WHERE ra_user_id = users.user_id
                    AND ra_status = 'Active'
                    AND ra_rithmic_status = 'Active'
                    AND ra_elite = 1) AS active_elites
            FROM users
            LEFT JOIN rithmic_accounts ON users.user_id = rithmic_accounts.ra_user_id
            INNER JOIN affiliates ON users.user_id = aff_user_id
            $whereStmt
            GROUP BY users.user_id
            ORDER BY users.user_id DESC
            $limitStmt
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
        }

        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * Checks if a user has an active dispute. This is true if there
     * exists a purchase marked as disputed and the user is not on the
     * dispute whitelist.
     *
     * @param int $userId The ID of the user to check.
     * @return bool True if there is an active dispute, false otherwise.
     */
    public static function hasActiveDispute(int $userId): bool
    {
        $stmt = Database::prepare(
            "SELECT 1 
            FROM purchases
            INNER JOIN users ON user_id = pur_user_id
            WHERE pur_user_id = :userId
                AND pur_disputed = 1
                AND user_dispute_whitelist IS NULL
            LIMIT 1"
        );

        $stmt->bindValue(":userId", $userId, ParameterType::INTEGER);
        $result = $stmt->executeQuery();

        return (bool) $result->fetchOne();
    }
    public static function getUserKycStatus(int $user_id): int
    {
        // Prepare a SQL query to count the distinct IP addresses for the specified user
        $stmt = Database::prepare("SELECT user_ekyc_status FROM users WHERE user_id = :user_id");
        $stmt->bindValue(":user_id", $user_id, ParameterType::INTEGER);

        $result = $stmt->executeQuery();

        // Fetch and return the count of unique IPs
        return $result->fetchOne();
    }
    public static function getPayoutOnboardStatus(int $user_id): int
    {
        // Prepare a SQL query to count the distinct IP addresses for the specified user
        $stmt = Database::prepare("SELECT user_payout_onboard_status FROM users WHERE user_id = :user_id");
        $stmt->bindValue(":user_id", $user_id, ParameterType::INTEGER);

        $result = $stmt->executeQuery();

        // Fetch and return the count of unique IPs
        return $result->fetchOne();
    }

    /**
     * Checks if a user has made any purchases yet.
     *
     * @param int $user_id The ID of the user to check.
     * @return bool True if the user is new, false otherwise.
     */
    public static function isNewCustomer(int $userId): bool
    {
        $stmt = Database::prepare(<<<SQL
            SELECT count(*)
            FROM purchases
            WHERE
                pur_user_id = :userId AND
                pur_p_id != :trialId
        SQL);

        $stmt->bindValue(":userId", $userId, ParameterType::INTEGER);
        $stmt->bindValue(':trialId', Products::TRIAL_ID, ParameterType::INTEGER);
        $result = $stmt->executeQuery();

        return (int)$result->fetchOne() === 0;
    }

    /**
     * Retrieve the next attempt number for a provided user without a lock
     * @param int $userId
     * @return int
     */
    public static function getNextAttemptNumber(int $userId): int|false
    {
        // now that the lock is in place, we can pull/return the attempt id
        $query = Database::prepare(<<<SQL
            SELECT user_rithmic_last_account
            FROM users
            WHERE user_id = :userId
        SQL);

        $query->bindValue(":userId", $userId, ParameterType::INTEGER);
        $result = $query->executeQuery();

        return ($result->fetchOne() ?? 0) + 1;
    }

    /**
     * Get a named lock for a provided user and return their next attempt id for safe use
     * Note: assumes the user id has already been validated as belonging to an existing user
     * @param int $userId
     * @return int|false attempt number on success, false on failure
     */
    public static function getAttemptLock(int $userId, int $timeoutSeconds = 10): int|false
    {
        if (false === Database::getNamedLock($userId, $timeoutSeconds)) {
            // if we waited 10 seconds for this lock and STILL did not get it, we have big issues. This condition should be extremely rare (other process would have to be stuck)
            // TODO: implement originally planned retry table for this scenario so we dont have to rerun this function manually
            return false;
        }

        if (false === $attemptId = self::getNextAttemptNumber($userId)) {
            Database::releaseNamedLock($userId);
            return false;
        }

        // what is listed is the current attempt in use, but we want the next available number. Adding to null gets funky, hence the parenthesis
        return $attemptId;
    }

    /**
     * Compatibility function to increment the attempt number in the users table. This column will be removed before too long
     * NOTE: should only be run by a process that holds a named attempt lock given by Users::getAttemptLock
     * @param int $userId
     * @return bool whether or not the update was successful (only false if user does not exist)
     */
    public static function incrementUserAttemptNumber(int $userId): bool
    {
        $query = Database::prepare(<<<SQL
            UPDATE users
            SET user_rithmic_last_account = user_rithmic_last_account + 1
            WHERE user_id = :userId
        SQL);

        $query->bindValue(":userId", $userId, ParameterType::INTEGER);
        $result = $query->executeQuery();

        return $result->rowCount() === 1;
    }

    /**
     * Grab a list of user ids that have TOV users but have yet to sign market data agreements. Responses are keyed by TOV user id
     * @return array[]
     */
    public static function getTradovateUsersWithNoMarketDataSignature(): array
    {
        // old version of this inner joined purchases which was not only slow, but unneeded because we only ever create a tradovate id for users who purchase
        $result = Database::query(<<<SQL
            SELECT
                user_id,
                user_tradovate_id
            FROM users
            WHERE
                user_tradovate_id IS NOT NULL AND
                user_tradovate_id != 0 AND
                user_tradovate_agreements IS NULL
            GROUP BY user_id
        SQL);

        return $result->fetchAllAssociativeIndexed();
    }

    /**
     * @param int $userId
     * @param int $tovUserSubscriptionId
     * @param int $subscriptionStartUnixTime
     * @throws \InvalidArgumentException
     * @return bool
     */
    public static function updateUserTradovateSubscriptionDetails(int $userId, int $tovUserSubscriptionId, int $subscriptionStartUnixTime): bool
    {
        $query = Database::prepare(<<<SQL
            UPDATE users SET
                user_tradovate_subscription_id = :subId,
                user_tradovate_subscription_start = :subStart
            WHERE user_id = :userId
        SQL);

        $query->bindValue(":subId", $tovUserSubscriptionId, ParameterType::INTEGER);
        $query->bindValue(":subStart", $subscriptionStartUnixTime, ParameterType::INTEGER);
        $query->bindValue(":userId", $userId, ParameterType::INTEGER);
        $result = $query->executeQuery();

        return $result->rowCount() === 1;
    }

    /**
     * Retrieve a list of users similar to the user with the specified ID. Similarity is determined by matching properties such as signup/login IP, address, city, state, zipcode, and country.
     * The list is sorted by the total score of matched properties, with the highest score at the top.
     * Each user in the list contains the full name, email, score, and a list of matching properties.
     * Users with fewer than two matched properties are filtered out.
     * @param int $userId The user ID to retrieve the list of similar users for.
     * @return array A list of similar users, sorted by score in descending order.
     * @throws \Exception
     */
    public static function getSimilarUsers(int $userId): array
    {
        $similarUsers = [];

        // Weights for ranking
        $weights = [
            'user_signup_ip' => 45,
            'user_login_ip' => 50,
            'user_address' => 16,
            'user_address2' => 15,
            'user_city' => 14,
            'user_state' => 13,
            'user_zipcode' => 12,
            'user_country' => 6
        ];

        $queries = [];
        foreach ($weights as $column => $weight) {
            $queries[] =
                "SELECT user_id, 
                CONCAT(user_fname, ' ', user_lname) AS full_name,
                user_email,
                    CASE WHEN {$column} IS NULL OR {$column} = '' THEN 0 ELSE {$weight} END AS weight, 
                        '{$column}' AS matching_property 
            FROM users 
            WHERE (LOWER({$column}) = LOWER(:{$column}) OR {$column} IS NULL OR {$column} = '') 
                AND user_id != :user_id";
        }

        $sql = implode(" UNION ALL ", $queries);
        $stmt = Database::prepare($sql);

        $currentUser = self::getUserById($userId);
        foreach ($weights as $column => $weight) {
            $stmt->bindValue(":{$column}", $currentUser[$column], ParameterType::STRING);
        }

        $stmt->bindValue(":user_id", $userId, ParameterType::INTEGER);
        $result = $stmt->executeQuery();

        while ($row = $result->fetchAssociative()) {
            if (!isset($similarUsers[$row['user_id']])) {
                $similarUsers[$row['user_id']] = [
                    'full_name' => $row['full_name'],
                    'user_email' => $row['user_email'],
                    'score' => 0,
                    'matching_properties' => []
                ];
            }

            $similarUsers[$row['user_id']]['score'] += $row['weight'];
            if ($row['weight'] > 0) {
                $similarUsers[$row['user_id']]['matching_properties'][] = $row['matching_property'];
            }
        }

        // Filter out users with fewer than two matched properties
        $similarUsers = array_filter($similarUsers, fn($user) => \count($user['matching_properties']) >= 1);
        uasort($similarUsers, fn($a, $b) => $b['score'] - $a['score']);

        return $similarUsers;
    }

    /***************************************************************************
     *                               USER NOTES                                *
     ***************************************************************************/

    /**
     * Inserts a user note into the database.
     *
     * @param int $adminId The ID of the admin creating the note.
     * @param int $userId The ID of the user the note is for.
     * @param string $subject The subject of the note.
     * @param string $body The body of the note.
     * @param int|null $emoji The emoji to display with the note (optional).
     */
    public static function insertNote(int $adminId, int $userId, string $subject, string $body, ?int $emoji = null): void
    {
        $stmt = Database::prepare(<<<SQL
            INSERT INTO user_notes (un_admin_id, un_user_id, un_subject, un_body, un_emoji)
            VALUES (:admin_id, :user_id, :subject, :body, :emoji)
        SQL);

        $stmt->bindValue(":admin_id", $adminId, ParameterType::INTEGER);
        $stmt->bindValue(":user_id", $userId, ParameterType::INTEGER);
        $stmt->bindValue(":subject", $subject, ParameterType::STRING);
        $stmt->bindValue(":body", $body, ParameterType::STRING);
        $stmt->bindValue(":emoji", $emoji, ParameterType::INTEGER);
        $stmt->executeQuery();
    }

    /**
     * Retrieves a paginated list of user notes.
     *
     * @param int $userId The ID of the user whose notes to retrieve.
     * @param int $page The page number to retrieve (default is 1).
     * @param int $limit The number of notes to retrieve per page (default is 3).
     * @return array An array of user note data, with each element being an associative array representing a single note.
     */
    public static function getNotes(int $userId, int $page = 1, int $limit = 3): array
    {
        $offset = ($page - 1) * $limit;

        $stmt = Database::prepare(<<<SQL
            SELECT * FROM user_notes
            WHERE un_user_id = :userId
            ORDER BY un_id DESC
            LIMIT :limit OFFSET :offset
        SQL);

        $stmt->bindValue(":userId", $userId, ParameterType::INTEGER);
        $stmt->bindValue(":limit", $limit, ParameterType::INTEGER);
        $stmt->bindValue(":offset", $offset, ParameterType::INTEGER);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
     * Retrieves the total number of user notes for the given user ID.
     *
     * @param int $userId The ID of the user whose notes to count.
     * @return int The total number of user notes for the given user ID.
     */
    public static function getTotalNotes(int $userId): int
    {
        $stmt = Database::prepare("SELECT COUNT(*) FROM user_notes WHERE un_user_id = :userId");
        $stmt->bindValue(":userId", $userId, ParameterType::INTEGER);
        $result = $stmt->executeQuery();

        return $result->fetchOne();
    }

    public static function getAverageEmojiMood(int $userId): string
    {
        $stmt = Database::prepare("SELECT un_emoji FROM user_notes WHERE un_user_id = :userId");
        $stmt->bindValue(":userId", $userId, ParameterType::INTEGER);
        $result = $stmt->executeQuery();

        $emojis = $result->fetchFirstColumn();

        if (empty($emojis)) {
            return '';
        }

        // can safely divide by the count due to empty check above
        $average = round(array_sum($emojis) / \count($emojis));
        return Core::getEmoji($average);
    }

    /**
     * Given a state name or iso2, ensure it is normalized to iso2
     * @param string $state either state name or iso2. Whatever was sent by the frontend
     * @param string $countryCode 2 letter country code
     * @return string
     */
    public static function normalizeStateInput(string $state, string $countryCode): string
    {
        $upperState = mb_strtoupper($state);
        $query = Database::prepare(<<<SQL
            SELECT iso2
            FROM states
            WHERE
                country_code = ? AND (
                    UPPER(name) = ? OR
                    iso2 = ?
                )
        SQL);

        $query->bindValue(1, $countryCode);
        $query->bindValue(2, $upperState);
        $query->bindValue(3, $upperState);
        $result = $query->executeQuery();

        if (false === $iso2 = $result->fetchOne()) {
            // if we could not find the state, leave the entry alone
            return $state;
        }

        return $iso2;
    }

    /**
     * Retrieves the platform credentials for a given user ID.
     *
     * @param int $userId The ID of the user whose platform credentials to retrieve.
     * @param string|null $platform The specific platform to retrieve credentials for. If null, all credentials will be returned.
     * @return array An array of platform credential data, with each element being an associative array representing a single credential.
     */
    public static function getPlatformCredentials(int $userId, ?string $platform = null): array
    {
        $sql = <<<SQL
            SELECT platform, username, password 
            FROM platform_credentials 
            WHERE user_id = :userId
        SQL;

        if ($platform !== null) {
            $sql .= " AND platform = :platform";
        }

        $stmt = Database::prepare($sql);

        $stmt->bindValue(":userId", $userId, ParameterType::INTEGER);
        if ($platform !== null) {
            $stmt->bindValue(":platform", $platform, ParameterType::STRING);
        }
        $result = $stmt->executeQuery();

        if ($platform !== null) {
            $row = $result->fetchAssociative();
            return $row ?: [];
        }

        return $result->fetchAllAssociative();
    }

    /**
     * Add trade break or update record with new times
     * @param int $userId
     * @param int $startTimestamp
     * @param int $endTimestamp
     * @return void
     */
    public static function addOrUpdateTradeBreak(int $userId, int $startTimestamp, int $endTimestamp): void
    {
        // no need to check if start is before end since that is baked into the db
        $query = Database::prepare(<<<SQL
            INSERT INTO trade_breaks (user_id, start_timestamp, end_timestamp)
            VALUES (:userId, :start, :end)
            ON DUPLICATE KEY UPDATE
                start_timestamp = VALUES(start_timestamp),
                end_timestamp = VALUES(end_timestamp)
        SQL);

        $query->bindValue(":userId", $userId, ParameterType::INTEGER);
        $query->bindValue(":start", $startTimestamp, ParameterType::INTEGER);
        $query->bindValue(":end", $endTimestamp, ParameterType::INTEGER);
        $query->executeQuery();
    }

    /**
     * Delete a trade break entry for the provided user, if one exists
     * @param int $userId
     * @return void
     */
    public static function clearTradeBreakForUser(int $userId): void
    {
        $query = Database::prepare(<<<SQL
            DELETE FROM trade_breaks
            WHERE user_id = :userId
        SQL);

        $query->bindValue(":userId", $userId, ParameterType::INTEGER);
        $query->executeQuery();
    }

    /**
     * Quick check to see if a user has ever enabled push notifications before if you do not have access to a User object
     * Returns false if no user exists with the provided id, but could be changed to throw an InvalidArgumentException if desired
     * @param int $userId
     * @return bool
     */
    public static function userHasEnabledPushNotifications(int $userId): bool
    {
        $query = Database::prepare(<<<SQL
            SELECT user_activated_push_notifications
            FROM users
            WHERE user_id = :userId
        SQL);

        $query->bindValue(":userId", $userId, ParameterType::INTEGER);
        return 1 === $query->executeQuery()->fetchOne();
    }

    /**
     * Given one or more user ids, attempt to mark them as having enabled push notifications
     * @param int[] $userIds
     * @throws InvalidArgumentException if no user ids are provided
     * @return void
     */
    public static function markUsersEnabledPushNotifications(int ...$userIds): void
    {
        if (empty($userIds)) {
            throw new InvalidArgumentException("Must provide at least one user id");
        }

        $query = Database::prepareWithInClause(<<<SQL
            UPDATE users
            SET user_activated_push_notifications = 1
            WHERE user_id IN (:placeholders)
        SQL, $userIds);
        $query->executeQuery();
    }
}
