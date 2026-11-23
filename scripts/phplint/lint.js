const fs=require('fs'),path=require('path');const Engine=require('php-parser');
const p=new Engine({parser:{php8:true,suppressErrors:false,extractDoc:false},ast:{withPositions:true}});
let bad=0,n=0;
function walk(d){for(const f of fs.readdirSync(d)){const fp=path.join(d,f);const s=fs.statSync(fp);
 if(s.isDirectory())walk(fp);else if(f.endsWith('.php')){n++;try{p.parseCode(fs.readFileSync(fp,'utf8'),fp);}catch(e){bad++;console.log('ERR',fp,e.message,'line',e.lineNumber);}}}}
walk(process.argv[2]);console.log('checked',n,'php files, errors:',bad);
