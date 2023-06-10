#!/bin/bash -eux

sleep 10

i=0
while [ $i -ne 100 ]
do
  i=$(($i+1))
  echo "$i"
  usleep 100
done

exit 2
