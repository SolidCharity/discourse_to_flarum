# Project

This php script will migrate a Discourse-Forum to Flarum.

For a blog post about this project, read https://www.pokorra.de/2018/10/migrating-a-forum-from-discourse-to-flarum/

## Features

- Configurable migration steps.
- Imports posts, discussions and users.
- Automatically tries to fix formatting.

WARNINGS:
- Not able to import user passwords.
- Cannot migrate uploaded media yet.

## Usage Instructions

1. Create a fresh forum using the standard install instructions. You can use the ansible playbook [ansible/flarum.yml](flarum.yml) like this:

```
# assuming that 192.168.122.52 is the IP address of your container dedicated for the migration
ansible-playbook flarum.yml -u root -e working_host=192.168.122.52
```

2. Install PostgreSQL and load your Discourse backup. You can use the ansible playbook [ansible/migration.yml](migration.yml) like this:

```
ansible-playbook migration.yml -u root -e working_host=192.168.122.52
```

3. The migration.yml playbook already downloads the migration script to /root/flarummigration inside the container
4. Install the required composer packages:

```
cd /root/flarummigration
composer update
```

4. Copy the migrate-example.yaml to migrate.yaml, and modify the database credentials if they are configured differently than by the ansible playbooks. Replace the flarum prefix in the migrate.yml file

```
cd /root/flarummigration
cp migrate-example.yaml migrate.yaml
# perhaps you need to modify the database credentials in migrate.yaml if you have your own setup
# if your prefix is not fl_ for the flarum tables
sed -i "s/fl_//g" migrate.yaml
```

5. Run the script

```
cd /root/flarummigration
php discourse_to_flarum.php
```

6. After modifications to either discourse_to_flarum.php or migrate.yaml, you can reset the flarum database and rerun the migration:

```
cd /root/flarummigration
zcat ../flarum.sql.gz | mysql -u flarum flarum -p
php discourse_to_flarum.php
```

## Installation on CentOS-7:

(see the Ansible playbook linked above, which does the installation for you...)


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
   grant all on flarum.* to flarum@localhost identified by 'flarum';
   update mysql.user set password=password('secretflarum') where user='root';
 
 su postgres
   psql 
     create user discourse password 'discourse';
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

