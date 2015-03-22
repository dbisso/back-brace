Back Brace
==========

### What?

A very basic backup tool. It does two things:

- Syncs a local folder with a remote location
- Uploads a daily dump of a database to a remote location

### Why?

Mainly an excuse to experiment with [FlySystem](http://flysystem.thephpleague.com/). Currently the only supported destination is an S3 bucket, but it would be trivial to add extra destinations that FlySystem supports.

### Should I use it?

Still experimental so use at your own risk. I am not responsible if it deletes your files or corrupts your morals.