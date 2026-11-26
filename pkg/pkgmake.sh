#!/bin/sh

TMP=`pwd`; cd `dirname $0`; BASEDIR=`pwd`; cd $TMP

all() {
	echo "# building: $app_pkgname"
	clean && import && pkg
}

_init() {
	app_pkgid="recipebox"
    app_displayname="Recipe Box"
    app_version=`head -1 $BASEDIR/../app/VERSION | sed 's/[ 	].*//g'`
    app_revision=`git log --pretty=oneline | wc -l | xargs`
    app_build=`git rev-parse --short HEAD`

    app_pkgname="$app_pkgid-$app_version-$app_revision-$app_build"
}

import() {
	echo "##### importing"
	[ -d BUILD ] && rm -rf BUILD
	mkdir BUILD
	cp -r ../app/* BUILD 2> /dev/null
	find BUILD -depth -name ".DS_Store" -exec rm {} \;
	find BUILD -depth -name ".gitignore" -exec rm {} \;
	find BUILD -depth -name ".gitkeep" -exec rm {} \;
	rm -rf BUILD/config/*
	rm -rf BUILD/var/state/*
	rm -rf BUILD/var/sessions/*
	rm -rf BUILD/public/uploads/*
	rm -rf BUILD/public/images/*
	rm -rf BUILD/tests
	rm -rf BUILD/phpunit.xml
	rm -rf BUILD/phpunit.xml

	chmod 777 BUILD/config
	chmod 777 BUILD/var/state
	chmod 777 BUILD/var/sessions
	chmod 777 BUILD/public/uploads
	chmod 777 BUILD/public/images
	echo "`head -1 BUILD/VERSION` (Rev: $app_revision Build: $app_build)" > BUILD/VERSION
}

pkg() {
	echo "##### packaging"
	
	PKG=$app_pkgid-$app_version
	mv BUILD $PKG
	tar cfz $app_pkgname.tar.gz $PKG
	echo "# created $app_pkgname.tar.gz"
	zip -r $app_pkgname.zip $PKG >/dev/null
	echo "# created $app_pkgname.zip"
}

clean() {
	echo "##### cleaning"
	rm -rf BUILD
	rm -rf recipebox-*
}

deploy() {
	echo "##### deploying"
	
	if [ ! -f .deploy-urls ]; then
		echo "Creating empty file .deploy-urls ..."
		echo "# sftp://user@www.domain.com/subdomain/folder" > .deploy-urls  
	fi
	
	IFS="
"
	for line in `grep -v "^#" .deploy-urls`; do
		$BASEDIR/sftp_upload.sh $line recipebox-*/*
	done
}

if [ $# -eq 0 ]; then
	echo "Usage: $0 <action>"
	echo
	echo "ACTIONS:"
	declare -F | awk '{print $3}' | grep -v ^_ | awk '{print "    "$1}'
	exit
fi

action="$1"
shift

declare -F "$action" >/dev/null
[ $? -ne 0 ] && echo "no such action: $action" && exit 1

cd $BASEDIR
_init

$action $*
echo "##### done"

