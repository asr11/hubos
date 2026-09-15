---
name: hebrew-keyboard-mapping
description: Automatically translate English gibberish typed by mistake back to Hebrew using standard QWERTY layout mapping. Use this skill whenever the user types something that looks like random English characters but they are a Hebrew speaker.
---

# Hebrew Keyboard Mapping (ג'יבריש לעברית)

When a Hebrew-speaking user accidentally types in English (e.g., "thl bert vehk"), they likely forgot to switch their keyboard language. 

Your task is to mentally (in your `<thinking>` block) map the English characters back to Hebrew characters using the standard QWERTY layout before responding. 

## Mapping Key
* q -> /
* w -> '
* e -> ק
* r -> ר
* t -> א
* y -> ט
* u -> ו
* i -> ן
* o -> ם
* p -> פ
* a -> ש
* s -> ד
* d -> ג
* f -> כ
* g -> ע
* h -> י
* j -> ח
* k -> ל
* l -> ך
* ; -> ף
* ' -> ,
* z -> ז
* x -> ס
* c -> ב
* v -> ה
* b -> נ
* n -> מ
* m -> צ
* , -> ת
* . -> ץ
* / -> .

## Instructions
1. Notice if the user's input looks like English gibberish (e.g., "n,h?", "thl", "tbdkh").
2. Translate it character by character using the mapping above.
3. Once translated, respond naturally to the Hebrew meaning of the prompt. 
4. In your response, briefly acknowledge that you translated their typo (e.g., "(זיהיתי שהקלדת בטעות באנגלית: 'מתי?')").
