#!/bin/bash -eux

echo "Hello World!"

#sleep 10

i=0
while [ $i -ne 50 ]
do
  i=$(($i+1))
  echo "$i"
  usleep 1000
done

exit 0
