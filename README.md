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

```yaml

#yaml-migration-config
---
authentification:
  old-database:
    host: localhost
    name: discourse
    user: discourse
    password: secret
  new-database:
    host: localhost
    name: flarum
    user: flarum
    password: secret
steps:
- action: COPY
  enabled: true
  old-table: posts
  new-table: fl_posts
  columns:
    id: id
    user_id: user_id
    topic_id: discussion_id
    cooked*: content
    created_at: time
    updated_at: edit_time
- action: RUN_COMMAND
  enabled: true
  command: UPDATE fl_posts SET type='comment'
- action: COPY
  enabled: true
  old-table: topics
  new-table: fl_discussions
  columns:
    id: id
    title: title
    posts_count++: comments_count
    participant_count: participants_count
    created_at: start_time
    user_id: start_user_id
    updated_at: last_time
    last_post_user_id: last_user_id
    slug: slug
    archetype?private_message: is_private
- action: COPY
  enable: true
  old-table: categories
  new-table: fl_tags
  columns:
    id: id
    name: name
    slug: slug
    description: description
    rnd_color**: color
    position: position
    parent_category_id: parent_id
- action: COPY
  enabled: true
  old-table: users
  new-table: fl_users
  columns:
    id: id
    username: username
    email: email
    password_hash: password
    created_at: join_time
    last_posted_at: last_seen_time
- action: RUN_COMMAND
  enabled: true
  command: DELETE FROM fl_users WHERE password='NULL'

```

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
   grant all privileges on all tables in schema public  to discourse;
```

## Authors

Original script by [robrotheram](https://github.com/robrotheram/phpbb_to_flarum)

Modified versions by:
- [VIRUXE](https://github.com/viruxe/phpbb_to_flarum)
- [Reflic](https://github.com/Reflic/phpbb_to_flarum)
- [TBits.net](https://github.com/TBits)

## License

This script is licensed under the [MIT license](LICENSE)

