#!/bin/bash
# build.sh
# script to build Debian package for remote.it connectd Installer
# sorts out Lintian errors/warnings into individual
# text files
pkg=connectd
ver=2.1.15
MODIFIED="July 21, 2020"
SCRIPT_DIR="$(cd $(dirname $0) && pwd)"
TEST_DIR="$SCRIPT_DIR"/../test
pkgFolder="$pkg"
# set architecture
controlFilePath="$pkgFolder"/DEBIAN
controlFile="$controlFilePath"/control
# current user account
user=$(whoami)
echo $user
# debugging flag, set to 0 to skip tests

#-------------------------------------------------
# setOption() is used to change settings in the connectd_$1 file

setOption()
{
    sedFilename="$pkgFolder"/usr/bin/connectd_$1
    sudo sed -i '/'"^$2"'/c\'"$2=$3 $4 $5 $6 $7"'' "$sedFilename"
}

#-------------------------------------------------
setEnvironment()
{
    sudo sed -i "/Architecture:/c\Architecture: $1" "$controlFile"

    setOption "options" "Architecture" "$1"

# delete any remaining binary files from the previous pass
    for i in $(find "$pkgFolder"/usr/bin/ -type f -name "connectd.*")
    do
        sudo rm "$i"
    done

    for i in $(find "$pkgFolder"/usr/bin/ -type f -name "connectd_schannel.*")
    do
        sudo rm "$i"
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
        sudo dpkg-deb --build "$1"
        # only run lintian if we are really making a Debian package
        ret=$(runLintian "$1".deb)
    else
        sudo dpkg-deb --build "$1"
        ret=$?
    fi
    sudo chown -R $user:$user "$1"
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

#-----------------------------------
echo "build.sh starting..."
gzip -9 "$pkgFolder"/usr/share/doc/$pkg/*.man

# change owner of all files to current user for manipulations
# later, will change owner of all files to root:root
# prior to executing dpkg-deb
sudo chown -R "$user":"$user" "$pkgFolder"

# save current folder to write output file to
cwd="$(pwd)/build"
mkdir -p $cwd

# build() takes 4 parameters: PLATFORM, arch, buildDeb, and tag (optional)
# PLATFORM indicates the remote.it daemon architecture, e.g. arm-linaro-pi
# buildDeb=0 means make a tar file.  buildDeb=1 means make a Debian file
# arch is the Debian architecture, e.g. armhf or amd64
# not required to pass in "arch" if buildDeb=0
# tag is an optional string to put in the file name to distinguish Debian packages
# which have the same "arch" but different "PLATFORM"

build() {
    echo
    echo "========================================"

    echo
    PLATFORM=$1
    buildDeb=$2
    if [ $buildDeb -eq 1 ]; then
        arch=$3
    else
# give it a default arch, it doesn't matter as we are just building a deb from which to extract the tar file
        arch="amd64"
    fi
    # get the optional 4th parameter to use as a file name tag
    if [ "$4" != "" ]; then
        tag="$4"
    else
        tag=""
    fi
    setEnvironment "$arch" "$PLATFORM"
    # put build date into connected_options
    setOption options "BUILDDATE" "\"$(date)\""
    # create symlink so that /usr/bin/connectd points to the installed daemon
    # use -f to force overwrite if it's already there
    ln -sf "/usr/bin/connectd.$PLATFORM" "$pkgFolder/usr/bin/connectd"
    # create symlink so that /usr/bin/connectd_schannel_link points to the 
    # installed daemon
    # there is already a "connectd_schannel" which is the start/stop script
    # use -f to force overwrite if it's already there
    ln -sf "/usr/bin/connectd_schannel.$PLATFORM" "$pkgFolder/usr/bin/connectd_schannel_link"

    # get Version string from DEBIAN/control file and write it to connectd_options
    # for tar files, since there is no concept of package "version" there
    version=$(grep -i version "$controlFile" | awk '{ print $2 }')
    setOption options "VERSION" "$version"

    # clean up and recreate md5sums file
    cd "$pkgFolder"
    if [ -e md5sums ]; then
        sudo rm md5sums
    fi
    if [ -e DEBIAN/md5sums ]; then
        sudo rm DEBIAN/md5sums
    fi
    sudo chmod 777 DEBIAN
    sudo find -type f ! -regex '.*?DEBIAN.*' -exec md5sum "{}" + | grep -v md5sums > md5sums
    sudo chmod 775 DEBIAN
    sudo mv md5sums DEBIAN
    sudo chmod 644 DEBIAN/md5sums
    cd ..

    if [ "$buildDeb" -eq 1 ]; then

        echo "Building Debian package for architecture: $arch"
        echo "PLATFORM=$PLATFORM"
        # tag variable was added to allow building different Debian packages with the same architecture
        # e.g. for Vyos I had to make a package using an older daemon architecture but it's still considered
        # amd64 or i386 architecture from the dpkg program's point of view
        if [ "$tag" != "" ]; then
            echo "tag = $tag"
        fi

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
            exit 1
        fi

        filename="${pkg}_${version}_$arch$tag".deb
        sudo mv "$pkgFolder".deb "$cwd/$filename"
    else
        echo "Building tar package for PLATFORM: $PLATFORM"
        # we are making a tar file, but first  we make a Debian file
        # then extract the /usr, /etc and /lib folders.
        buildDebianFile "$pkgFolder"

        if [ $? == 1 ];then
            echo "Errors encountered during build."
            exit 1
        fi

        echo "Extracting contents to tar file"
        sudo ./scripts/extract-scripts.sh "$pkgFolder".deb
        filename="${pkg}_${version}_$PLATFORM$tag".tar
        sudo mv "$pkgFolder".deb.tar "$cwd/$filename"

    fi
    ls -l "$cwd/$filename"

}

#
# echo $SCRIPT_DIR
# echo $TEST_DIR

build_one_and_test()
{
# now define and create each build 1 by 1
# the amd64 Debian package should be first as we test installing that package and running
# several registration scenarios prior to building everything else

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86_64-ubuntu16.04 1 amd64

#---------------------------------------------------
# in this section we do some basic installer tests using the amd64 Debian
# package running on the build container

sudo "$TEST_DIR"/dpkg/dpkg-install.sh
if [ $? -ne 0 ]; then
    echo "dpkg installation failure!"
    exit 1
fi
}

build_all()
{
#---------------------------------------------------
# 32-bit i386 Debian package
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86-ubuntu16.04 1 i386

# aarch64 package - tar package with static linking
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build aarch64-ubuntu16.04_static 0

# arm-v5t_le package - tar package with dynamic linking
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build arm-v5t_le 0

# arm-v5t_le package - tar package with static linking
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build arm-v5t_le_static 0

# aarch64 package - tar package with dynamic linking
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build aarch64-ubuntu16.04 0

# arm64 package - Debian package with dynamic linking
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build aarch64-ubuntu16.04 1 arm64

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
build arm-android 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
build arm-android_static 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build arm-linaro-pi 1 armhf

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build arm-linaro-pi 1 armel

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86-etch 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86_64-etch 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86-ubuntu16.04 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86-ubuntu16.04_static 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86-linaro_uClibc 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86-linaro_uClibc_static 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86_64-ubuntu16.04 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86_64-ubuntu16.04_static 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
build arm-linaro-pi 0

setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
build arm-gnueabi 0

# here we are using the tag "-etch" to create an i386 Debian architecture package for the older
# Debian "Etch" architecture that needs to be distinct from the one for Ubuntu 16.04
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86-etch 1 i386 -etch

# here we are using the tag "-etch" to create an amd64 Debian architecture package for the older
# Debian "Etch" architecture that needs to be distinct from the one for Ubuntu 16.04
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build x86_64-etch 1 amd64 -etch

# mips-24kec
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mips-24kec 0

# mips-24kec-musl
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mips-24kec-musl 0

# mips-34kc
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mips-34kc 0

# mips-gcc-4.7.3
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mips-gcc-4.7.3 0

# mipsel-gcc342
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mipsel-gcc342 0

# mipsel-bmc5354
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mipsel-bmc5354 0

# now build static versions of all MIPS tar packages
# mips-24kec_static
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mips-24kec_static 0

# mips-34kc_static
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mips-34kc_static 0

# mips-gcc-4.7.3_static
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mips-gcc-4.7.3_static 0

# mipsel-gcc342_static
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mipsel-gcc342_static 0

# mipsel-bmc5354_static
setOption options "mac" '$'"(ip addr | grep ether | tail -n 1 | awk" "'{ print" '$2' "}')"
setOption options "BASEDIR" ""
build mipsel-bmc5354_static 0
}

#==== program starts here

if [ "$1" == "one" ]; then
    build_one_and_test
elif [ "$1" == "all" ]; then
    build_all
else
    echo "build.sh requires parameter one or all"
	exit 1
fi

echo "======   build.sh $ver completed   =============="
exit 0
