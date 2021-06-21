<?php

namespace Bitcot\AwsSecretsManager;

use Exception;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Env;
use stdClass;

class secrets
{
    /**
     * @var string
     */
    private $cacheKey;

    public function __construct()
    {
        $this->cacheKey = Env::get('BSM_CACHE_KEY', 'bsmAwsSecrets');
    }

    /**
     * @throws Exception
     */
    public static function get($key){
        $secretsManager = new secrets();
        if ($secretsManager->noService()){
            return null;
        }
        $secrets = $secretsManager->fetchSecrets();
        return property_exists($secrets, $key) ? $secrets->$key->value : null;
    }

    /**
     * @throws Exception
     */
    public static function getAll()
    {
        $secretsManager = new secrets();
        if ($secretsManager->noService()){
            return new stdClass();
        }
        $secrets = $secretsManager->fetchSecrets();
        foreach ($secrets as $key=>$value){
            $secrets->$key = $value->value;
        }
        return $secrets;
    }

    public static function clearSecrets(): bool
    {
        $secretsManager = new secrets();
        if ($secretsManager->noService()){
            return false;
        }
        Cache::forget($secretsManager->cacheKey);
        return Cache::get($secretsManager->cacheKey) === null;
    }

    /**
     * @throws Exception
     */
    public static function isLatest($key, $update = true): bool // todo test no key in awsSM retrycount senario
    {
        $secretsManager = new secrets();
        if ($secretsManager->noService()){
            return false;
        }
        $secrets = $secretsManager->fetchSecrets();
        if (!property_exists($secrets, $key)){
            $secrets->$key = new stdClass();
            $secrets->$key->value = '';
            $secrets->$key->retryCount = 1;
            $secrets->$key->status = 'failing';
        }
        $secretsManager->updateSecrets($secrets);
//        dd($secrets->$key->value);
        $retryCount = $secrets->$key->retryCount;
        switch (true) {
            /** @noinspection PhpDuplicateSwitchCaseBodyInspection */ case !is_numeric($retryCount):
            $secrets->$key->retryCount = 1;
            $secrets->$key->status = 'failing';
            break;
            case $retryCount <= Env::get('BSM_MAX_RETRY_COUNT', 10):
                $secrets->$key->retryCount++;
                $secrets->$key->status = 'failing';
                break;
            case $retryCount > Env::get('BSM_MAX_RETRY_COUNT', 10):
                $secrets->$key->status = 'failed';
                $secretsManager->updateSecrets($secrets);
                return true;
            default:
                $secrets->$key->retryCount = 1;
                $secrets->$key->status = 'failing';
                break;
        }
        $secretsManager->updateSecrets($secrets);
        $aws = $secretsManager->fetchSecretsFromAWS();
        $aws = property_exists($aws, $key) ? $aws->$key : null;
        $result = $aws === $secrets->$key->value;
        if (!$result && $update)
        {
            Cache::forget($secretsManager->cacheKey);
        }
        return $result;
    }

    /** @noinspection PhpUnused */
    public static function markAsWorking($key): bool
    {
        $secretsManager = new secrets();
        if ($secretsManager->noService()){
            return false;
        }
        $secrets = Cache::get($secretsManager->cacheKey);
        if ($secrets->$key->retryCount != 0){
            $secrets->$key->retryCount = 0;
            $secrets->$key->status = 'active';
        }
        Cache::put($secretsManager->cacheKey, $secrets);
        return true;
    }

    /**
     * @throws Exception
     */
    public static function status(): stdClass
    {
        $secretsManager = new secrets();
        if ($secretsManager->noService()){
            return 'service is down';
        }
        $secrets = $secretsManager->fetchSecrets();
        $activeSecrets = array();
        $failingSecrets = array();
        $failedSecrets = array();
        $unknownSecrets = array();
        foreach ($secrets as $key=>$value){
            switch ($value->status) {
                case 'active':
                    array_push( $activeSecrets, $key);
                    break;
                case 'failing':
                    array_push($failingSecrets, $key);
                    break;
                case 'failed':
                    array_push($failedSecrets, $key);
                    break;
                default:
                    array_push($unknownSecrets, $key);
                    break;
            }
        }
        $status = new stdClass();
        $status->total = array_merge($activeSecrets,$failingSecrets,$failedSecrets,$unknownSecrets);
        $status->active = $activeSecrets;
        $status->failing = $failingSecrets;
        $status->failed = $failedSecrets;
        $status->unknown = $unknownSecrets;
        return $status;
    }

    /**
     * @throws Exception
     */
    public static function storedInfo($key = null)
    {
        $secretsManager = new secrets();
        if ($secretsManager->noService()){
            return 'service is down';
        }
        $secrets = $secretsManager->fetchSecrets();
        if ($key != null){
            return property_exists($secrets, $key) ? $secrets->$key : null;
        }
        return $secrets;
    }


    /**
     * @throws Exception
     */
    private function decryptSecrets($secrets)
    {
        foreach ($secrets as $key=>$value){
            try {
                $secrets->$key->value = Crypt::decryptString($value->value);
            } catch (DecryptException $e) {
                throw new Exception("SecretsDecryptionFailureException");
            }
        }
    }

    /**
     * @throws Exception
     */
    private function fetchSecrets()
    {
        $secrets = Cache::rememberForever($this->cacheKey, function () {
            $awsKeys = $this->fetchSecretsFromAWS();
            $secrets = new stdClass();
            foreach ($awsKeys as $key=>$value){
                $secrets->$key = new stdClass();
                $secrets->$key->value = Crypt::encryptString($value);
                $secrets->$key->retryCount = 0;
                $secrets->$key->status = 'active';
            }
            return $secrets;
        });
        $this->decryptSecrets($secrets);
        return $secrets;
    }

    /** @noinspection PhpUndefinedVariableInspection */
    private function fetchSecretsFromAWS()
    {
        // Create a Secrets Manager Client
        $client = new SecretsManagerClient([
            'profile' => Env::get('BSM_AWS_PROFILE', 'bsmAwsSecrets'),
            'version' => 'latest',
            'region' =>  Env::get('BSM_AWS_REGION', 'us-east-2'),
        ]);

        $secretName = Env::get('BSM_SECRET_NAME', 'test/local');

        try {
            $result = $client->getSecretValue([
                'SecretId' => $secretName,
            ]);

        } catch (AwsException $e) {
            $error = $e->getAwsErrorCode();
            if ($error == 'DecryptionFailureException') {
                // Secrets Manager can't decrypt the protected secret text using the provided AWS KMS key.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InternalServiceErrorException') {
                // An error occurred on the server side.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InvalidParameterException') {
                // You provided an invalid value for a parameter.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'InvalidRequestException') {
                // You provided a parameter value that is not valid for the current state of the resource.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
            if ($error == 'ResourceNotFoundException') {
                // We can't find the resource that you asked for.
                // Handle the exception here, and/or rethrow as needed.
                throw $e;
            }
        }
        // Decrypts secret using the associated KMS CMK.
        // Depending on whether the secret is a string or binary, one of these fields will be populated.
        if (isset($result['SecretString'])) {
            $secret = $result['SecretString'];
        } else {
            $secret = base64_decode($result['SecretBinary']); // we wont be using this
        }

        //we are assuming that $secret will either contains a json string or null
        return json_decode($secret);
    }

    private function noService(): bool
    {
        if (env('APP_KEY') === '' || env('APP_KEY' === null)){
            return true;
        }
        return false;
    }

    /**
     * @throws Exception
     */
    private function updateSecrets($secrets){
        foreach ($secrets as $key=>$value){
            $secrets->$key->value = Crypt::encryptString($value->value);
        }
        Cache::put($this->cacheKey, $secrets);
        $this->decryptSecrets($secrets);
    }
}

