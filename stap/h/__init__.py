"""Various helpers"""

import pkgutil

__all__ = []
for loader, name, is_pkg in pkgutil.walk_packages(__path__):
    __all__.append(name)
    module = loader.find_module(name).load_module(name)
    exec("{} = module".format(name))
