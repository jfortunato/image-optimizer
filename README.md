# Image Optimizer
Optimize an entire directory of images and track the directory for changes.

### Requirements
- PHP 7.3 or later
- LINUX server
- composer installed
- npm installed


### Installation
Clone the repo and navigate into it.
```
git clone https://github.com/jfortunato/image-optimizer.git
cd image-optimizer
```

Install PHP dependencies with composer install.
```
composer install
```

Install NPM dependencies with npm install.
```
npm install
```

Ensure the directory that will store the optimized images exists.
```
mkdir /path/to/optimized-images
```

### Basic Usage
The script takes 2 arguments, the input/ directory and the output/ directory. Depending on the amount of images, it could take a very long time on first run.
```
./image-optimizer input-directory/ /path/to/optimized-images/
```

### Automatically mirroring the un-optimized images
The script is setup to mirror the input directory with the output directory. So if you re-run the script with the exact same arguments, any new or modified images will be found and optimized. Also, any deleted images (from the input directory), will also be deleted from the output directory. You could run this script on a cron to automate this process.
```
@daily /path/to/image-optimizer --yes input-directory/ /path/to/optimized-images/ > /dev/null 2>&1
```

### Serving the optimized images with apache
You can use apache rewrites to serve the optimized image even when the un-optimized image is requested.

> This is just one way to do this, that enables multiple virtual hosts to opt-out of the functionality by creating a file named `image-optimizations.disable` in their document root.

###### In a global apache file.
```apacheconfig
# Full path to the optimized images directory, without a trailing slash.
Define optimized_images_path /srv/optimized-images


Define optimized_images_alias /optimized-images
Alias "${optimized_images_alias}/" "${optimized_images_path}/"
<Directory "${optimized_images_path}/">
    Options Indexes MultiViews FollowSymLinks
    Header add Optimized-Image: "true"
    AllowOverride None
    Require all granted
    Order deny,allow
    Allow from 127.0.0.0/255.0.0.0 ::1/128
</Directory>


# From http://httpd.apache.org/docs/current/mod/mod_rewrite.html
# If used in per-server context (i.e., before the request is mapped to the filesystem) SCRIPT_FILENAME and REQUEST_FILENAME cannot contain the full local filesystem path since the path is unknown at this stage of processing. Both variables will initially contain the value of REQUEST_URI in that case. In order to obtain the full local filesystem path of the request in per-server context, use an URL-based look-ahead %{LA-U:REQUEST_FILENAME} to determine the final value of REQUEST_FILENAME.
RewriteEngine On
RewriteOptions InheritDown
RewriteCond "%{DOCUMENT_ROOT}/image-optimizations.disable" !-f
RewriteCond "%{LA-U:REQUEST_FILENAME}" "^/(.+)\.(jpe?g|png|gif|svg)$"
RewriteCond "${optimized_images_path}/%1.%2" -f
# Use passthrough flag so the alias will take effect
RewriteRule ^(.+)$ ${optimized_images_alias}/%1.%2 [PT]
```

### Advanced Usage
##### Use the ```--no-delete``` option to keep optimized images that don't exist in the input/ directory.

###### optimize all images under input-directory/, but if an optimized image exists under optimized-images/ and not under input-directory/, do not delete it.
```
./image-optimizer --no-delete input-directory/ /path/to/optimized-images/
```

##### Use the ```--only-include="path/to/match"``` option to filter down the input/ directory images and only include those within certain sub-directories.

###### only include files whose absolute filepath contain the string "wp-content/uploads/2018" or "wp-content/uploads/2019"
```
./image-optimizer --only-include="wp-content/uploads/2018" --only-include="wp-content/uploads/2019" input-directory/ /path/to/optimized-images/
```
