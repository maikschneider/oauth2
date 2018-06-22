<?php
declare(strict_types=1);

namespace Mfc\OAuth2\Services;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Mfc\OAuth2\ResourceServer\AbstractResourceServer;
use Mfc\OAuth2\ResourceServer\GitLab;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Service\AbstractService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;

/**
 * Class OAuth2LoginService
 * @package Mfc\OAuth2\Services
 * @author Christian Spoo <cs@marketing-factory.de>
 */
class OAuth2LoginService extends AbstractService
{
    /**
     * @var array
     */
    private $loginData;
    /**
     * @var array
     */
    private $authenticationInformation;
    /**
     * @var AbstractUserAuthentication
     */
    private $parentObject;
    /**
     * @var ?AccessToken
     */
    private $currentAccessToken;
    /**
     * @var array
     */
    private $extensionConfig;
    /**
     * @var AbstractResourceServer
     */
    private $resourceServer;

    /**
     * @param $subType
     * @param array $loginData
     * @param array $authenticationInformation
     * @param AbstractUserAuthentication $parentObject
     */
    public function initAuth(
        $subType,
        array $loginData,
        array $authenticationInformation,
        AbstractUserAuthentication &$parentObject
    ) {
        $this->extensionConfig = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['oauth2']);

        $this->loginData = $loginData;
        $this->authenticationInformation = $authenticationInformation;
        $this->parentObject = $parentObject;

        if (!is_array($_SESSION)) {
            @session_start();
        }
    }

    public function getUser()
    {
        if ($this->loginData['status'] !== 'login') {
            return null;
        }

        $oauthProvider = GeneralUtility::_GP('oauth-provider');
        if (empty($oauthProvider)) {
            return null;
        }
        $this->initializeOAuthProvider($oauthProvider);

        if (empty($_GET['state'])) {
            $this->sendOAuthRedirect();
            exit;
        } elseif ($this->isOAuthRedirectRequest()) {
            try {
                $this->currentAccessToken = $this->resourceServer->getOAuthProvider()->getAccessToken('authorization_code',
                    [
                        'code' => GeneralUtility::_GET('code')
                    ]);
            } catch (\Exception $ex) {
                return false;
            }

            if ($this->currentAccessToken instanceof AccessToken) {
                try {
                    $user = $this->resourceServer->getOAuthProvider()->getResourceOwner($this->currentAccessToken);
                    $record = $this->findOrCreateUserByResourceOwner($user, $oauthProvider);

                    if (!$record) {
                        return false;
                    }

                    return $record;
                } catch (\Exception $ex) {
                    return false;
                }
            }

        } else {
            unset($_SESSION['oauth2state']);
        }

        return null;
    }

    private function initializeOAuthProvider($oauthProvider)
    {
        switch ($oauthProvider) {
            case 'gitlab':
                $this->resourceServer = new GitLab(
                    $this->extensionConfig['gitlabAppId'],
                    $this->extensionConfig['gitlabAppSecret'],
                    'gitlab',
                    $this->extensionConfig['gitlabServer'],
                    $this->extensionConfig['gitlabRepositoryName']
                );
                break;
        }
    }

    /**
     * @return string
     */
    private function sendOAuthRedirect()
    {
        $authorizationUrl = $this->resourceServer->getAuthorizationUrl();
        $_SESSION['oauth2state'] = $this->resourceServer->getOAuthProvider()->getState();
        HttpUtility::redirect($authorizationUrl, HttpUtility::HTTP_STATUS_303);
    }

    private function isOAuthRedirectRequest()
    {
        $state = GeneralUtility::_GET('state');
        return (!empty($state) && ($state === $_SESSION['oauth2state']));
    }

    /**
     * @param ResourceOwnerInterface $user
     * @param string $providerName
     * @return array|null
     */
    private function findOrCreateUserByResourceOwner(ResourceOwnerInterface $user, string $providerName): ?array
    {
        $oauthIdentifier = $this->resourceServer->getOAuthIdentifier($user);

        // Try to find the user first by its OAuth Identifier
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($this->authenticationInformation['db_user']['table']);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $record = $queryBuilder
            ->select('*')
            ->from($this->authenticationInformation['db_user']['table'])
            ->where(
                $queryBuilder->expr()->eq(
                    'oauth_identifier',
                    $queryBuilder->createNamedParameter(
                        $oauthIdentifier,
                        Connection::PARAM_STR
                    )
                ),
                $this->authenticationInformation['db_user']['check_pid_clause'],
                $this->authenticationInformation['db_user']['enable_clause']
            )
            ->execute()
            ->fetch();

        if (!$record) {
            $record = $queryBuilder
                ->select('*')
                ->from($this->authenticationInformation['db_user']['table'])
                ->where(
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->eq(
                            'username',
                            $queryBuilder->createNamedParameter(
                                $this->resourceServer->getUsernameFromUser($user),
                                Connection::PARAM_STR
                            )
                        ),
                        $queryBuilder->expr()->eq(
                            'email',
                            $queryBuilder->createNamedParameter(
                                $this->resourceServer->getEmailFromUser($user),
                                Connection::PARAM_STR
                            )
                        )
                    ),
                    $this->authenticationInformation['db_user']['check_pid_clause'],
                    $this->authenticationInformation['db_user']['enable_clause']
                )
                ->execute()
                ->fetch();
        }

        if (!is_array($record)) {
            $record = [
                'crdate' => time(),
                'tstamp' => time(),
                'admin' => (int)$this->resourceServer->userShouldBeAdmin($user),
                'disable' => 0,
                'starttime' => 0,
                'endtime' => 0,
                'oauth_identifier' => $this->resourceServer->getOAuthIdentifier($user),
                'password' => 'invalid'
            ];

            $expirationDate = $this->resourceServer->userExpiresAt($user);
            if ($expirationDate instanceof \DateTime) {
                $record['endtime'] = $expirationDate->format('U');
            }

            $record = $this->resourceServer->updateUserRecord($user, $record);

            $queryBuilder->insert(
                $this->authenticationInformation['db_user']['table']
            )
                ->values($record)
                ->execute();

            $record = $this->parentObject->fetchUserRecord(
                $this->authenticationInformation['db_user'],
                $this->resourceServer->getUsernameFromUser($user)
            );
        } else {
            if (/* should update permissions */ true) {
                $this->resourceServer->loadUserDetails($user);

                $record = array_merge(
                    $record,
                    [
                        'admin' => (int)$this->resourceServer->userShouldBeAdmin($user),
                        'disable' => 0,
                        'starttime' => 0,
                        'endtime' => 0,
                        'oauth_identifier' => $this->resourceServer->getOAuthIdentifier($user)
                    ]
                );

                $expirationDate = $this->resourceServer->userExpiresAt($user);
                if ($expirationDate instanceof \DateTime) {
                    $record['endtime'] = $expirationDate->format('U');
                }
            }

            $record = $this->resourceServer->updateUserRecord($user, $record);

            $qb = $queryBuilder->update(
                $this->authenticationInformation['db_user']['table']
            )
                ->where(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter(
                            $record['uid'],
                            Connection::PARAM_STR
                        )
                    )
                );

            foreach ($record as $key => $value) {
                $qb->set($key, $value);
            }

            $qb->execute();
        }

        return is_array($record) ? $record : null;
    }

    public function authUser(array $userRecord)
    {
        $result = 100;

        if ($userRecord['oauth_identifier'] !== '') {
            $user = $this->resourceServer->getOAuthProvider()->getResourceOwner($this->currentAccessToken);

            if ($this->currentAccessToken instanceof AccessToken && $this->resourceServer->userIsActive($user)) {
                $result = 200;
            }
        }

        return $result;
    }
}