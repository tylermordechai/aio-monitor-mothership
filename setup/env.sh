# setup/env.sh: settings shared by the setup scripts. Sourced, not run.
# Nothing secret goes in this file; it is in the repo (MS-OPS-07).

AIO_IP=192.168.0.191                 # the mothership's fixed IP (M-024)
AIO_LAN=192.168.0.0/24               # the only range allowed in (M-025)
AIO_LAN_PREFIX=192.168.0.            # same range, for simple peer checks
AIO_ETC=/etc/aio-mothership          # secrets and PKI, never in the repo
AIO_DB_NAME=aio
AIO_PHP_VERSION=8.5                  # Ubuntu 26.04.1 packages (M-029)
AIO_MARIADB_SERIES=11.8              # Ubuntu 26.04.1 main (M-029)
AIO_MARIADB_DATADIR=/var/lib/mariadb # not /var/lib/mysql on 26.04 (M-029)
