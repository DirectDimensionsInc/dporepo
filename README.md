# DPO's 3D Repository

A Symfony project created on November 30, 2017, 8:53 pm.

A port from the [PHP Slim-based project](https://github.com/Smithsonian/dporepo_slim) to [Symfony 3.4](https://symfony.com/).

## Installation

### Install webserver and database
TODO: needs specification for supported environments, and for tools

### Prepare website and PHP
Create website root
Create empty MySQL database, and user account.
Enable ldap extension in php.ini

### Clone the Repositories
In addition to the repository code you will need the JSON schemas.

In the web root- 
```
git clone git@github.com:Smithsonian/dporepo.git
```

In the /web path under the web root create a directory "json".
Inside web/json-
```
git clone git@github.com:Smithsonian/dporepo-schemas.git
```

### Parameters (app/config/parameters.yml)

If you have a filled-out `parameters.yml` file, move it into the app/config directory.

If not, you will be prompted during the installation to provide these settings.

The database settings must match the database and user account created in step Prepare website.

### Install using Composer

```
cd dporepo

composer install
```
TODO: Right now users have to disable the EDAN client within composer.json in order for install to work.

### Launch UI
Using a browser navigate to the homepage.
If you see PDO errors (can't find file), set unix_socket underneath doctrine:, dbal: within app/config/config.yml

Click Install
If installation says it succeeded but you have no database, the most likely culprit is your version of MySQL doesn't support json fields. 
TODO: Specify min MySQL version
TODO: Temp cheat, change the 2 JSON fields to varchar(8000) within database_create.sql

Click Login
Click Register, and create a new user account.
You should now have access to all repo functions.

