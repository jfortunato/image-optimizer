# Image Optimizer
Mirror an entire directory of images with a directory of those same images optimized with imagemin.


### Requirements
- PHP 7 or later
- LINUX server
- composer installed
- npm installed


### Prerequisites
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
mkdir optimized-images
```

### Usage
The script takes 2 arguments, the input/ directory and the output/ directory. Depending on the amount of images, it could take a very long time on first run.
```
./image-optimizer input-directory/ /path/to/optimized-images/
```

### Automatically mirroring the un-optimized images
The script is setup to mirror the input directory with the output directory. So if you re-run the script with the exact same arguments, any new or modified images will be found and optimized. Also, any deleted images (from the input directory), will also be deleted from the output directory. You could run this script on a cron to automate this process.
```
@daily /path/to/image-optimizer input-directory/ /path/to/optimized-images/ > /dev/null 2>&1
```

### Serving the optimized images with apache
You can use apache rewrites to serve the optimized image even when the un-optimized image is requested.

> This is just one way to do this, that enables multiple virtual hosts to opt-in to the functionality via an .htaccess file.

###### In a global apache file.
```apacheconfig
Define optimized_images_path /srv/image-optimizer/optimized-images
Define optimized_images_alias /optimized-images

Alias /optimized-images/ "/srv/image-optimizer/optimized-images/"
<Directory "/srv/image-optimizer/optimized-images/">
    Options Indexes MultiViews FollowSymLinks
    AllowOverride None
    Require all granted
    Order deny,allow
    Allow from 127.0.0.0/255.0.0.0 ::1/128
</Directory>
```

###### In .htaccess file
```apacheconfig
# Automatically serve optimized images
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} (.+)\.(jpe?g|png|gif|svg)$
RewriteCond ${optimized_images_path}%1.%2 -f
RewriteRule ^(.+)$ ${optimized_images_alias}%1.%2 [L]
</IfModule>
```
