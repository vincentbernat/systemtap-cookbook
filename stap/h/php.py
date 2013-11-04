"""PHP helpers"""

import subprocess

def extension_dir():
    """Get PHP extension directory."""
    extension_dir = subprocess.check_output(["php", "-r",
                                             'echo ini_get("extension_dir");'])
    return extension_dir
