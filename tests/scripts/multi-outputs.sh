#!/bin/bash -eux

function handle_sigterm()
{
  echo "sigterm out" >&1
  echo "sigterm err" >&2
  exit 0
}

trap handle_sigterm SIGTERM

echo "out" >&1
echo "err" >&2

sleep 0.5
