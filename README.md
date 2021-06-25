# bitcot/aws-secrets-manager

A library to get secret key value pairs from AWS Secrets Manager

This library encrypts the retrieved values and stores it in the cache indefinitely. Getting the latest key value pairs from AWS and updating them in the cache can be achieved with any one of the following methods:

- Clear the cache by calling [```secrets::clearSecrets();```](#clear-all-the-secrets-from-cache)
    - Laravel implementation example
- Setup Automatic update from AWS at runtime by adding [```secrets::isLatest('key');```](#check-if-the-key-value-pair-in-the-cache-matches-with-the-one-in-aws) and [```secrets::markAsWorking('key');```](#mark-a-secret-key-value-pair-as-working) in a try-catch block where the secret is used
    - [Implementation approximation](#implementation-approximation)
- [Laravel specific] Use the Artisan command ```php artisan cache:clear```

### Prerequisites

- [Setup a secret in AWS](https://docs.aws.amazon.com/secretsmanager/latest/userguide/manage_create-basic-secret.html)
- [Create an AWS access key ID and secret access key](https://aws.amazon.com/premiumsupport/knowledge-center/create-access-key/)
- [Setting up Credentials for the AWS SDK ](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html)

## Installation

Installation is super-easy via [Composer](https://getcomposer.org/):

```bash
$ composer require bitcot/aws-secrets-manager
```

or add it by hand to your ```composer.json``` file.

## Setup

1.  Setup environment variables in ```.env``` file in the root of your
    project. [Additional information](https://github.com/vlucas/phpdotenv)

    ```dotenv
    APP_KEY=<base64_string_preferably_32_characters_long>
    BSM_AWS_PROFILE=<AWS_credentials_profile>
    BSM_SECRET_NAME=<AWS_secret_name>
    BSM_AWS_REGION=<AWS_secret_region>
    BSM_CACHE_KEY=<secrets_manager_cache_key>
    BSM_MAX_RETRY_COUNT=<failed_secrets_max_retries>
    ```
    - **APP_KEY** [required] base64 string preferably 32 characters long used for encryption [Additional information](https://laravel.com/docs/8.x/encryption#configuration)
      - If this is 'not set'/'empty string' all the methods in this library will return failed response values (```null``` in case of ```secrets::get($key)```)
    - **BSM_AWS_PROFILE** [Default: default] Profile for AWS access key ID and secret access key stored in ~/.aws/credentials
    - **BSM_SECRET_NAME** [Default: project/env] Name of the secret stored in AWS
    - **BSM_AWS_REGION** [Default: us-east-2] AWS Region in which the secret is stored
    - **BSM_CACHE_KEY** [Default: bsmAwsSecrets] Key of the secrets stored in the cache
    - **BSM_MAX_RETRY_COUNT** [Default: 10] No of failed attempts before marking the key as inactive. This is applicable only if automatic update of values is being used

2.  Include this namespace to retrieve secrets
    ```php
    use Bitcot\AwsSecretsManager\secrets;
    ```

## Usage

### Retrieving value using a key

```php
secrets::get('key');
```

#### Returns

- Value of the given key
    - ```null``` If the secret is an empty string
    - ```null``` If no secret exists for the given key in AWS

### Retrieving all the key value pairs

```php
secrets::getAll();
```

#### Returns

- Key value pairs object
    - If no key value pairs exists in AWS, an Empty object would be returned

### Get All the info of secrets

```php
secrets::getInfo();
```

#### To get the values of only one key value pair, Pass the key while calling this method

```php
secrets::getInfo('key');
```

#### Returns

An object containing the value, retry count and status of every key stored in the cache

- ```null``` If the key is passed while calling the method and no secret exists with that key.

### Clear all the secrets from cache

```php
secrets::clearSecrets();
```

#### Returns

```true``` If the secrets in cache are successfully cleared, ```false``` Otherwise.

### Check if the key value pair in the cache matches with the one in AWS

##### This can be used to set up automatic update of the values in cache if a new value is avaliable in aws

```php
secrets::isLatest('key');
```

This method clears all the secrets stored in the cache by default if latest value in AWS does not match with the one in cache.
To stop this, pass ```false``` as the second argument.

```php
secrets::isLatest('key', false);
```

#### Returns

```true``` If the value in AWS matches with the one in cache, ```false``` Otherwise.

- Returns ```true``` if the given key doesn't exist in AWS

### Mark a secret key value pair as working

#### This should be clubbed with ```isLatest()``` to achieve automatic update of the values in cache if a new value is available in aws

```php
secrets::markAsWorking('key');
```

#### Returns

```true``` If the key value pair has been marked as working and set retry count to 0, ```false``` Otherwise.

### Get status of the secrets

```php
secrets::status();
```

#### Returns

An object containing arrays of Total, active, failing, failed and unknown keys.

## Implementation types

### Manual update of the values in cache if a new value is available in aws
##### Get secrets
Include this namespace at the top of the file
```php
use Bitcot\AwsSecretsManager\secrets;
```
To retrieve the values
```php
echo secrets::get('key');
```
##### Update values from AWS
- Clear the cache by calling [```secrets::clearSecrets();```](#clear-all-the-secrets-from-cache)
    - Laravel implementation example
- [Laravel specific] Use the Artisan command ```php artisan cache:clear```

### <a name="implementation-approximation"></a> Automatic update of the values in cache if a new value is available in AWS[Approximation]

Include this namespace at the top of the file
```php
use Bitcot\AwsSecretsManager\secrets;
```
To retrieve the latest values
```php
To be updated...
```

## To be continued...
