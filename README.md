# PetStoreBundle
An example repository for creating php symfony flex bundles


### Using this bundle
U can install this plugin by installing with command:
`composer require common-gateway/pet-store-plugin:dev-main`
in the directory where your composer.json lives.

### Creating your own bundle

##### Requirements
- [GitHub](https://github.com/login) account or organization
- [Packagist](https://packagist.org/login/) account
- [Composer](https://getcomposer.org/download/) or if your project is dockerized, [Docker Desktop](https://www.docker.com/products/docker-desktop/)

#### Using this template

To create your own symfony bundle. You can copy this repository for a fast start.

1. Login on [GitHub](https://github.com)
2. Use [this template](https://github.com/CommonGateway/PetStoreBundle/generate)
3. Name your bundle (CamelCase)
4. Press the green button `Create repository from template`
5. Update file names and namespace to your fitting 
   - Open composer.json, and change the name to your fitting. The first word should be the namespace and the second the name of your bundle. Check the autoload field to be set accordingly. Note: this is kebab-case. Also read: [naming your package](https://packagist.org/about#naming-your-package)
   - Open PetStoreBundle.php and change the Bundle name and namespace. The namespace should be the same as your package name in composer.json but in CamelCase. So common-gateway/pet-store-bundle becomes CommonGateway/PetStoreBundle
   - Rename the /Service and /ActionHandler accordingly (or delete if not used).
   - Rename the /DependencyInjection/PetStoreExtension.php to your BundleNameExtension.php
   - Rename the /Resources/config/services.xml service and handler to your new namespace and BundleName  

#### Upload to packagist

Before we can use our bundle and code, we must have it online on packagist so we can install with composer.

1. Upload to packagist  
   - Go to [Packagist](https://packagist.org)
   - Press `submit` in the top menu bar and paste your bundle's github repository link, the name from you composer.json will be used as package name.
   - If valid press `Submit`
2. Auto-update package
   - Go to your [packagist profile](https://packagist.org/profile/).
   - Press `Show API Token` and copy
   - Go to your new bundle's github repository
   - Go to your repository settings
   - Go to webhooks, and press `Add webhook`
   - As Payload URL, set https://packagist.org/api/github?username=yourPackagistUsername  
   - Keep SSL verification enabled
   - As secret, paste the copied API token from packagist
   - Set event on `Just push the event`
   - Press `Add webhook`
   - If you push new code it will now push to packagis. On packagist you should not see the auto-update warning anymore. If its still there check if your username and secret are set properly on the github webhook.

#### Using your code

To use the code in your library we first have to install it with composer.

Note: for docker add `docker-compose exec php` before all comands

1. Navigate with a command line to where your composer.json lives in the project you want to use this bundle.
   - Execute `composer require {full package name}:dev-main`
   - Docker users: restart your containers so symfony can recognize the new Bundle's namespace
2. Open a php file where you want to use a class.
   - Add the correct use statement (example `use CommonGateway\PetStoreBundle\Service\PetStoreService;`)
   - U can now use your class!

In the common gateway, if you want to use your code when triggered by an event with a action, make sure the class of the action object is set as the handler name including the namespace. For example if I want to use the PetStoreService I can set the PetStoreHandler as `CommonGateway\PetStoreBundle\ActionHandler\PetStoreHandler`.
