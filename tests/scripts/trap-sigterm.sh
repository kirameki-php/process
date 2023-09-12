#!/bin/bash

function handle_sigterm()
{
  sleep 5
}

trap handle_sigterm SIGTERM

while true
do
  sleep 5
done
