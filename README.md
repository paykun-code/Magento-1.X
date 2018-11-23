# Magento-1.X
Magento Version 1.X

# <h3>How To Generate Access token and API Secret :</h3>
You can find your Merchant Id in Paykun Dashboard.

You can generate Or Regenerate Access token and API Secret from login into your paykun admin panel, Then Go To : Settings -> Security -> API Keys. There you will find the generate button if you have not generated api key before.

If you have generated api key before then you will see the date of the api key generate, since you will not be able to retrieve the old api key (For security reasons) we have provided the re-generate option, so you can re-generate api key in case you have lost the old one.

Note : Once you re-generate api key your old api key will stop working immediately. So be cautious while using this option.

# <h3>Prerequisite</h3>
    Merchant Id (Please read 'How To Generate Access token and API Secret :')
    Access Token (Please read 'How To Generate Access token and API Secret :')
    Encryption Key (Please read 'How To Generate Access token and API Secret :')
    Wordpress 4.x compatible Woo-Commerce version must be installed and other payment method working properly.

# <h3>Installation</h3>
Note: Please backup your running source code and database first.
  1. Download the zip and extract it to the some temporary location.
  2. Copy 'app' directory and paste it in your root directory.
  3. Once Copy is done then clear the cache.
  4. Now login to the Magento admin and go to the Admin > System > Configuration > Sales > Payment Methods > Paykun Checkout
  5. Save the below configuration.

      * Enable                   - Yes
      * Payment Action          - Default
      * Merchant Id             - Staging/Production Merchant Id provided by Paykun
      * Access Token            - Staging/Production Access Token provided by Paykun
      * Encryption Key          - Staging/Production Encryption Key provided by Paykun
      * Debug                   - For trouble shooting, also enable magento log from the Configuration > Advanced > Log Settings > Set Enabled to Yes

  10. Now you will be able to see paykun payment method in the checkout page.

#<h3> In case of any query, please contact to support@paykun.com.</h3>