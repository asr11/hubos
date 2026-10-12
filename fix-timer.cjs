const fs = require('fs');
let content = fs.readFileSync('/home/asr11/ASHER/os.hub.co.il/src/contexts/TimerContext.tsx', 'utf8');
content = content.replace(/!== undefined \{/g, '!== undefined) {');
fs.writeFileSync('/home/asr11/ASHER/os.hub.co.il/src/contexts/TimerContext.tsx', content);
