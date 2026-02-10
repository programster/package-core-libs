Core Libs
========

This is a PHP package providing a set of static libraries that act to "extend" the standard library. 
These are built primarily with a focus on rapid development and ease of understanding over 
performance.

### Recommended Extensions
This package recommends various extensions such as mbstring in order to be able to perform some of
the provided methods. However, we did not wish to prevent this from allowing you to install the 
package in case you did not need to use these limited methods. Thus, such methods will raise 
the an `ExceptionMissingExtension` with details of which extension, if you try to execute a 
method, without having the recommended extension installed.
