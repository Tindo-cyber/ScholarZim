# Landing page image credits

The five photographs in `public/assets/landing/` are cropped and recompressed
from originals on Wikimedia Commons. All five are by the same photographer and
are released under **CC0 1.0 Universal** (public-domain dedication), which
permits copying, modification and commercial use with no attribution
requirement. They are credited here anyway, and so the provenance is on record.

Photographer: Kwameghana (Bright Kwame Ayisi)
Licence: CC0 1.0 - https://creativecommons.org/publicdomain/zero/1.0/

| File in the project | Source (Wikimedia Commons) | Original | Crop and output |
|---|---|---|---|
| `hero-student.jpg` | [A student raised his ask a question in class 02](https://commons.wikimedia.org/wiki/File:A_student_raised_his_ask_a_question_in_class_02.jpg) | 6036x4020 | top 71% of the frame, full width, 2800x1333 |
| `scholarfit-student.jpg` | [Students using a computer laptop](https://commons.wikimedia.org/wiki/File:Students_using_a_computer_laptop.jpg) | 6036x4020 | 4:3 crop, 1600x1200 |
| `student-section.jpg` | [A JHS student 01](https://commons.wikimedia.org/wiki/File:A_JHS_student_01.jpg) | 6036x4020 | 16:9 crop from the top, 1800x1012 |
| `provider-section.jpg` | [Wiki Data Training in Accra 1](https://commons.wikimedia.org/wiki/File:Wiki_Data_Training_in_Accra_1.jpg) | 6036x4020 | 16:9 crop from the top left, 1600x900 |
| `cta-background.jpg` | [JHS students listening to their teacher](https://commons.wikimedia.org/wiki/File:JHS_students_listening_to_their_teacher.jpg) | 6036x4020 | 3:1 band, 2400x800 |

## How they are used

- Every crop was chosen for the box it fills, not enlarged from a small image.
  `tests/Feature/LandingAssetsTest.php` enforces a minimum width of 2000px for
  the hero and 1200px for the others, so a low-resolution file cannot be
  committed by accident.
- The hero's left side is plain wall on purpose: the headline sits there under
  a dark gradient (see `.sz-hero-photo` and the gradient in
  `resources/views/public/index.blade.php`).
- Files are progressive JPEGs at quality 76-80. Keep the filenames stable; the
  Blade view references them directly.
- No text, logo or UI is baked into any photograph.

## Notes on the people shown

CC0 waives the photographer's copyright. It does not waive a pictured person's
personality or privacy rights. The photographs show schoolchildren and adults
at a school and a training session in Ghana, published by the photographer on
Commons. Replace any of them if you obtain locally taken photographs of
Zimbabwean students with signed releases.
