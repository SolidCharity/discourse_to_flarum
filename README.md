# Project

This php script will migrate a Discourse-Forum to Flarum.

## Features

- Configurable migration steps.
- Imports posts, discussions and users.
- Automatically tries to fix formatting.

WARNINGS:
- Not able to import user passwords.
- Cannot migrate uploaded media yet.

## Usage Instructions

1. Create a fresh forum using the standard install instructions.
2. Install PostgreSQL and load your Discourse backup.
3. Make sure PHP and the required libraries are installed.
4. Download script and configuration. (migrate.yaml)
5. Configure your export- and import databases.
6. install TextFormatter with composer: composer require s9e/text-formatter
7. Run the script and press 'ENTER' to start the first migration step.

## Required Libraries

The following libraries need to be installed to run this script:
- php-pecl-yaml

## Download

The sources are available at: https://github.com/TBits/discourse_to_flarum

## Example Configuration

see [migrate-example.yaml](migrate-example.yaml)

## Installation on CentOS-7:

```
 yum install epel-release
 yum install mariadb-server mariadb postgresql-server php php-pecl-yaml

 systemctl start mariadb
 systemctl enable mariadb
 postgresql-setup initdb
 systemctl start postgresql
 systemctl enable postgresql

 mysql -u root -p
   create database flarum;
   grant all on flarum.* to flarum@localhost identified by 'secretflarum';
   update mysql.user set password=password('secretflarum') where user='root';
 
 su postgres
   psql 
     create user discourse password 'secretdiscourse';
   createdb -T template0 --encoding UTF8 -O discourse discourse
 vi /var/lib/pgsql/data/pg_hba.conf
   comment out the other lines
   local    all     all    peer
 
 su postgres
   cat /tmp/dump.sql | psql discourse
   grant all privileges on all tables in schema public to discourse;
```

## Authors

Original script by [robrotheram](https://github.com/robrotheram/phpbb_to_flarum)

Modified versions by:
- [VIRUXE](https://github.com/viruxe/phpbb_to_flarum)
- [Reflic](https://github.com/Reflic/phpbb_to_flarum)
- [TBits.net](https://github.com/TBits)

## License

This script is licensed under the [MIT license](LICENSE)

