#!/bin/sh
#
# Blocks until PHP-FPM is accepting connections, then execs whatever it was
# given. Used as a prefix on the nginx program in supervisord.conf.
#
# WHY THIS EXISTS
#
# Supervisor has no dependency mechanism. `priority` decides the order the
# spawn calls are made in, and `startsecs` decides when a process is *declared*
# RUNNING and whether a quick exit counts as a failed start - but neither makes
# supervisor wait for one program before starting the next. Every autostart
# program is spawned in the same pass, microseconds apart. That is why the
# deploy log shows php-fpm and nginx starting together, and why nginx answered
# the platform's first request with "connect() failed (111: Connection refused)
# while connecting to upstream": php-fpm had been forked but had not yet bound
# its socket.
#
# One or two seconds of 502 is invisible on a normal day and fatal on a deploy,
# because the health check is the first request to arrive. So nginx waits for
# the socket rather than for a guessed number of seconds - if FPM is ready in
# 200ms the wait costs 200ms, and if the box is slow the wait stretches to match
# instead of failing.
#
# The probe is written in PHP rather than with nc or wait-for-it because this is
# a PHP image: the interpreter is the one dependency that is certainly present.
# It is a single process that polls internally, not one process per attempt.

set -e

FPM_HOST="${FPM_HOST:-127.0.0.1}"
FPM_PORT="${FPM_PORT:-9000}"
# Generous, because it is a ceiling rather than a delay: the wait ends the
# moment the socket answers. It only matters when FPM is genuinely broken, and
# there it should fail rather than hang forever.
FPM_WAIT_TIMEOUT="${FPM_WAIT_TIMEOUT:-60}"

php -r '
$host    = getenv("FPM_HOST") ?: "127.0.0.1";
$port    = (int) (getenv("FPM_PORT") ?: 9000);
$timeout = (int) (getenv("FPM_WAIT_TIMEOUT") ?: 60);
$started = microtime(true);
$deadline = $started + $timeout;

while (microtime(true) < $deadline) {
    // A TCP connect is the whole question: FPM binds the socket when it is
    // ready to serve, so anything that accepts is ready. Speaking FastCGI
    // would only add a way for the probe itself to be wrong.
    $socket = @fsockopen($host, $port, $errno, $error, 0.25);

    if ($socket !== false) {
        fclose($socket);
        printf("php-fpm accepting on %s:%d after %.2fs\n", $host, $port, microtime(true) - $started);
        exit(0);
    }

    usleep(100000);
}

fwrite(STDERR, sprintf("php-fpm did not accept on %s:%d within %ds\n", $host, $port, $timeout));
exit(1);
'

exec "$@"
