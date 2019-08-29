#!/usr/bin/env bash

# Students enrolled in data structures will also be enrolled in csci1199 for
# the "u19" term.  Later on, CS1 enrollment can also be enrolled into csci1199
# for the "f19" term.

# Those already enrolled in csci1199 will have their registration_section and
# manual_registration data points updated from data structures/CS1.

# Root required
if [[ $UID -ne 0 ]]; then
    echo "Root is required."
    exit 1
fi

# Process data structures enrollment to C++
# (that is, f19/csci1200 to u19/csci1199)
su postgres -c "psql submitty -c \"
INSERT INTO courses_users
    (SELECT 'u19', 'csci1199', user_id, user_group, registration_section, manual_registration FROM courses_users WHERE semester='f19' AND course='csci1200' AND user_group=4)
ON CONFLICT (semester, course, user_id)
    DO UPDATE SET registration_section=EXCLUDED.registration_section, manual_registration=EXCLUDED.manual_registration\""

# Uncomment all five lines, below, to allow processing CS1 enrollment to C++
# (that is, f19/csci1100 to f19/csci1199)

# su postgres -c "psql submitty -c \"
# INSERT INTO courses_users
#    (SELECT 'f19', 'csci1199', user_id, user_group, registration_section, manual_registration FROM courses_users WHERE semester='f19' AND course='csci1100' AND user_group=4)
# ON CONFLICT (semester, course, user_id)
#     DO UPDATE SET registration_section=EXCLUDED.registration_section, manual_registration=EXCLUDED.manual_registration\""
