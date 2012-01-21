Configuration
=============

Configuration options
---------------------

This module offers no configuration options.


Configuration example
---------------------

The recommended way to use this module is to have it loaded at the general
configuration level and to disable it only for specific networks if needed.

..  parsed-code:: xml

    <?xml version="1.0"?>
    <configuration
      xmlns="http://localhost/Erebot/"
      version="0.20"
      language="fr-FR"
      timezone="Europe/Paris">

      <modules>
        <!-- Other modules ignored for clarity. -->

        <module name="|project|"/>
      </modules>
    </configuration>


.. vim: ts=4 et
