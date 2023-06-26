#!/bin/bash -eux

while true; do
if [ "$1" = "--sleep" ]; then
    SLEEP=$2
    shift 2
else
    break
fi
done

EXIT=${1:-0}

if [ -n "${SLEEP}" ]; then
  echo "Sleeping for $SLEEP seconds"
  sleep "$SLEEP"
fi

echo "Exiting with $EXIT"
exit "$EXIT"
