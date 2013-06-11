#!/bin/bash
#
# ownCloud
#
# @author Thomas Müller
# @copyright 2012 Thomas Müller thomas.mueller@tmit.eu
#

DATADIR=data-autotest
BASEDIR=$PWD

# create autoconfig for sqlite, mysql and postgresql
cat > ./tests/autoconfig-sqlite.php <<DELIM
<?php
\$AUTOCONFIG = array (
  'installed' => false,
  'dbtype' => 'sqlite',
  'dbtableprefix' => 'oc_',
  'adminlogin' => 'admin',
  'adminpass' => 'admin',
  'directory' => '$BASEDIR/$DATADIR',
);
DELIM

cat > ./tests/autoconfig-mysql.php <<DELIM
<?php
\$AUTOCONFIG = array (
  'installed' => false,
  'dbtype' => 'mysql',
  'dbtableprefix' => 'oc_',
  'adminlogin' => 'admin',
  'adminpass' => 'admin',
  'directory' => '$BASEDIR/$DATADIR',
  'dbuser' => 'oc_autotest',	
  'dbname' => 'oc_autotest',	
  'dbhost' => 'localhost',
  'dbpass' => 'owncloud',	
);
DELIM

cat > ./tests/autoconfig-pgsql.php <<DELIM
<?php
\$AUTOCONFIG = array (
  'installed' => false,
  'dbtype' => 'pgsql',
  'dbtableprefix' => 'oc_',
  'adminlogin' => 'admin',
  'adminpass' => 'admin',
  'directory' => '$BASEDIR/$DATADIR',
  'dbuser' => 'oc_autotest',	
  'dbname' => 'oc_autotest',	
  'dbhost' => 'localhost',
  'dbpass' => 'owncloud',	
);
DELIM

cat > ./tests/autoconfig-oci.php <<DELIM
<?php
\$AUTOCONFIG = array (
  'installed' => false,
  'dbtype' => 'oci',
  'dbtableprefix' => 'oc_',
  'adminlogin' => 'admin',
  'adminpass' => 'admin',
  'directory' => '$BASEDIR/$DATADIR',
  'dbuser' => 'oc_autotest',
  'dbname' => 'XE',
  'dbhost' => 'localhost',
  'dbpass' => 'owncloud',
);
DELIM

function execute_tests {
	echo "Setup environment for $1 testing ..."
	# back to root folder
	cd $BASEDIR

	# revert changes to tests/data
	git checkout tests/data/*

	# reset data directory
	rm -rf $DATADIR
	mkdir $DATADIR

	# remove the old config file
	#rm -rf config/config.php
	cp tests/preseed-config.php config/config.php

	# drop database
	if [ "$1" == "mysql" ] ; then
		mysql -u oc_autotest -powncloud -e "DROP DATABASE oc_autotest"
	fi
	if [ "$1" == "pgsql" ] ; then
		dropdb -U oc_autotest oc_autotest
	fi
	if [ "$1" == "oci" ] ; then
		echo "drop the database"
		sqlplus -s -l / as sysdba <<EOF
			drop user oc_autotest cascade;
EOF

		echo "create the database"
		sqlplus -s -l / as sysdba <<EOF
			create user oc_autotest identified by owncloud;
			alter user oc_autotest default tablespace users
			temporary tablespace temp
			quota unlimited on users;
			grant create session
			, create table
			, create procedure
			, create sequence
			, create trigger
			, create view
			, create synonym
			, alter session
			to oc_autotest;
			exit;
EOF
	fi

	# copy autoconfig
	cp $BASEDIR/tests/autoconfig-$1.php $BASEDIR/config/autoconfig.php

	# trigger installation
	php -f index.php

	#test execution
	echo "Testing with $1 ..."
	cd tests
	rm -rf coverage-html-$1
	mkdir coverage-html-$1
	php -f enable_all.php
	if [ "$1" == "sqlite" ] ; then
		# coverage only with sqlite - causes segfault on ci.tmit.eu - reason unknown
		phpunit --configuration phpunit-autotest.xml --log-junit autotest-results-$1.xml --coverage-clover autotest-clover-$1.xml --coverage-html coverage-html-$1
	else
		phpunit --configuration phpunit-autotest.xml --log-junit autotest-results-$1.xml
	fi
}

#
# start test execution
#
if [ -z "$1" ]
  then
	execute_tests "sqlite"
	execute_tests 'mysql'
	execute_tests 'pgsql'
	execute_tests 'oci'
else
	execute_tests $1
fi

#
# NOTES on mysql:
#  - CREATE USER 'oc_autotest'@'localhost' IDENTIFIED BY 'owncloud';
#  - grant access permissions: grant all on oc_autotest.* to 'oc_autotest'@'localhost';
#
# NOTES on pgsql:
#  - su - postgres
#  - createuser -P (enter username and password and enable superuser)
#  - to enable dropdb I decided to add following line to pg_hba.conf (this is not the safest way but I don't care for the testing machine):
# local	all	all	trust
#
# NOTES on oci:
#  - it's a pure nightmare to install Oracle on a Linux-System
#  - DON'T TRY THIS AT HOME!
#  - if you really need it: we feel sorry for you
#
