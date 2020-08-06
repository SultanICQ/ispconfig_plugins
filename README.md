# ISPConfig Plugins

## Secure htaccess for ISPConfig domains - zzz_secure_htaccess_plugin.inc.php

This plugin adds the ability to secure the .htaccess of the domains.

We can customize some options adding **zzz_secure_htaccess_plugin.config.local.php** to the same plugin folder.

## Install the plugins

This repo allows you to test the plugins using a docker machine with a complete ISPConfig installation. See below.

But you can install the plugin to your current ISPConfig.

Clone this repo and then symlink the plugins you want inside /usr/local/ispconfig/server/plugins-enabled

For example:

```
cd /usr/local
git clone https://github.com/SultanICQ/ispconfig_plugins.git
cd /usr/local/ispconfig/server/plugins-enabled
ln -s /usr/local/ispconfig_plugins/plugins/zzz_secure_htaccess_plugin.inc.php
```

Then you can update **zzz_secure_htaccess_plugin.config.php** or create a copy as 
**zzz_secure_htaccess_plugin.config.local.php** to customize some options.

### Run the docker machine

*You can see all the info for this docker machine [here](https://hub.docker.com/r/jerob/docker-ispconfig).*

```
docker run --name ispconfig \
-e MAILMAN_EMAIL_HOST=test.com \
-e MAILMAN_EMAIL=test@test.com \
-e MAILMAN_PASS=pass \
-d \
-v $(pwd)/start.sh:/start.sh \
-v $(pwd)/www:/var/www \
-v $(pwd)/plugins:/home/plugins \
-p 20:20 \
-p 21:21 \
-p 30000-30009:30000-30009 \
-p 80:80 \
-p 443:443 \
-p 8080:8080 \
-p 53:53 \
-p 2222:22 \
jerob/docker-ispconfig /start.sh
```

**Start.sh** has been updated to create a symlink to this plugin inside ISPConfig
