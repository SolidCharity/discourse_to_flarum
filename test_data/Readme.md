Test database
=============

There is a dump of a PostgreSQL Discourse database in this directory.

This database was generated with the script https://github.com/discourse/discourse/blob/master/script/profile_db_generator.rb, with modifications documented in this gist: https://gist.github.com/tpokorra/b2e34238dea0243572e822f649a35bab/revisions

How to run that script:

```
./launcher enter app
cd /var/www/discourse
# modify the script to add modifications as described above
vi script/profile_db_generator.rb
RAILS_ENV=production sudo -H -E -u discourse bundle exec ruby script/profile_db_generator.rb
```

To create a backup of the database:

```
./launcher enter app
sudo -u discourse pg_dump discourse | gzip &gt; /shared/postgres_backup/discourse_pg`date '+%Y-%m-%d_%H-%M-%S'`.sql.gz
```

You find the result on your host machine, in directory /var/discourse/shared/standalone/postgres_backup.
