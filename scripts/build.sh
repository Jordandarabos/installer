#!/bin/bash
# build.sh
# script to build Debian package for remote.it connectd Installer
# sorts out Lintian errors/warnings into individual
# text files
pkg=connectd
ver=2.1.1
pkgFolder="$pkg"
# set architecture
controlFilePath="$pkgFolder"/DEBIAN
controlFile="$controlFilePath"/control
# current user account
user=$(whoami)

#-------------------------------------------------
# setOption() is used to change settings in the connectd_$1 file

setOption()
{
    sedFilename="$pkgFolder"/usr/bin/connectd_$1
    sed -i '/'"^$2"'/c\'"$2=$3 $4 $5 $6 $7"'' "$sedFilename"
}

#-------------------------------------------------
setEnvironment()
{
    sed -i "/Architecture:/c\Architecture: $1" "$controlFile"

    setOption "options" "Architecture" "$1"

    for i in $(find "$pkgFolder"/usr/bin/ -type f -name "connectd.*")
    do
        rm "$i"
    done

    for i in $(find "$pkgFolder"/usr/bin/ -type f -name "connectd_schannel.*")
    do
        rm "$i"
    done

    sudo cp ./assets/connectd."$2" "$pkgFolder"/usr/bin
    if [ $? -eq 1 ]; then
        echo "Error, missing file: connectd.$2"
        exit 1
    fi
    sudo chmod +x "$pkgFolder"/usr/bin/connectd."$2"
    sudo cp ./assets/schannel."$2" "$pkgFolder"/usr/bin/connectd_schannel."$2"
    if [ $? -eq 1 ]; then
        echo "Error, missing file: schannel.$2"
        exit 1
    fi
    sudo chmod +x "$pkgFolder"/usr/bin/connectd_schannel."$2"

    setOption options "PLATFORM" "$2"
    setOption control "PLATFORM" "$2"
}

# buildDebianFile takes 1 parameter, the package name/folder
# then runs lintian file checker
# and creates connectd.deb in the current folder.

buildDebianFile()
{
    # build reference DEB file
    ret=0
    sudo chown -R root:root "$1"
    if [ "$buildDeb" -eq 1 ]; then
        dpkg-deb --build "$1"
        # only run lintian if we are really making a Debian package
        ret=$(runLintian "$1".deb)
    else
        dpkg-deb --build "$1"
        ret=$?
    fi
    return $ret
}

#-------------------------------------------------
runLintian()
{
    ret_val=0
    # scan debian file for errors and warnings
    lintian -vi --show-overrides "$1"  > lintian-result.txt
    grep E: lintian-result.txt > lintian-E.txt
    grep W: lintian-result.txt > lintian-W.txt
    grep I: lintian-result.txt > lintian-I.txt
    grep X: lintian-result.txt > lintian-X.txt
    if [ -s lintian-E.txt ]; then
	ret_val=1
    fi
    return $ret_val
}

gzip -9 "$pkgFolder"/usr/share/doc/$pkg/*.man

# change owner of all files to current user for manipulations
# later, will change owner of all files to root:root
# prior to executing dpkg-deb
sudo chown -R "$user":"$user" "$pkgFolder"

# save current folder to write output file to
cwd="$(pwd)/build"
mkdir -p $cwd

build() {
    echo
    echo "========================================"

    echo

    setEnvironment "$arch" "$PLATFORM"
    # put build date into connected_options
    setOption options "BUILDDATE" "\"$(date)\""

    # clean up and recreate md5sums file
    cd "$pkgFolder"
    sudo chmod 777 DEBIAN
    sudo find -type f ! -regex '.*?DEBIAN.*' -exec md5sum "{}" + | grep -v md5sums > md5sums
    sudo chmod 775 DEBIAN
    sudo mv md5sums DEBIAN
    sudo chmod 644 DEBIAN/md5sums
    cd ..

    if [ "$buildDeb" -eq 1 ]; then

        echo "Building Debian package for architecture: $arch"
        echo "PLATFORM=$PLATFORM"

        #--------------------------------------------------------
        # for Deb pkg build, remove builddate.txt file
        # builddate.txt is used by generic tar.gz installers
        file="$pkgFolder"/etc/connectd/builddate.txt

        if [ -e "$file" ]; then
            rm "$pkgFolder"/etc/connectd/builddate.txt
        fi
        #--------------------------------------------------------
        buildDebianFile "$pkgFolder"

        if [ $? -eq 1 ];then
            echo "Errors encountered during build."
            cat lintian-E.txt
        fi

        version=$(grep -i version "$controlFile" | awk '{ print $2 }')
        filename="${pkg}_${version}_$arch".deb
        mv "$pkgFolder".deb "$cwd/$filename"
    else
        echo "Building tar package for PLATFORM: $PLATFORM"
        # we are making a tar file, but first  we make a Debian file
        # then extract the /usr, /etc and /lib folders.
        buildDebianFile "$pkgFolder"

        if [ $? == 1 ];then
            echo "Errors encountered during build."
        fi

        version=$(grep -i version "$controlFile" | awk '{ print $2 }')
        echo "Extracting contents to tar file"
        ./scripts/extract-scripts.sh "$pkgFolder".deb
        filename="${pkg}_${version}_$PLATFORM".tar
        mv "$pkgFolder".deb.tar "$cwd/$filename"

    fi

}

buildDeb=0
arch="armhf"
PLATFORM=arm-android
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "PSFLAGS" "ax"
build

buildDeb=0
arch="armhf"
PLATFORM=arm-android_static
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "PSFLAGS" "ax"
build

buildDeb=1
setOption options "PSFLAGS" "ax"
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
arch="armhf"
PLATFORM=arm-linaro-pi
setOption options "BASEDIR" ""
build

buildDeb=1
setOption options "PSFLAGS" "ax"
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
arch="armel"
PLATFORM=arm-linaro-pi
setOption options "BASEDIR" ""
build

buildDeb=0
setOption options "PSFLAGS" "ax"
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
arch="i386"
PLATFORM=x86-etch
setOption options "BASEDIR" ""
build

buildDeb=1
arch="amd64"
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
PLATFORM=x86_64-ubuntu16.04
setOption options "BASEDIR" ""
setOption options "PSFLAGS" "ax"
build

buildDeb=0
arch="armhf"
PLATFORM=arm-linaro-pi
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "PSFLAGS" "ax"
build

buildDeb=0
arch="arm-gnueabi"
PLATFORM=arm-gnueabi
setOption options "PSFLAGS" "w"
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
build

buildDeb=0
arch="amd64"
PLATFORM=x86_64-etch
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
setOption options "PSFLAGS" "ax"
build

ls -l "build/${pkg}"*.*
