#!/bin/bash -eux

function handle_sigterm()
{
  sleep 5
}

trap handle_sigterm SIGTERM

echo "trapped"

while true
do
  sleep 5
done
