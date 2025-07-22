#!/bin/bash

mdTemplate='/usr/local/Resources/anonymiseHVF/md_template_anon.md'
VFReportMask='/usr/local/Resources/anonymiseHVF/VFReport_mask.pdf'

filename=$( basename "$1" )
echo $filename

IFS='_' read -a parts <<< $filename

# Split filename into parts, last part with have .pdf so remove it
for key in "${!parts[@]}"
do
  val=(${parts[$key]})
  if [[ $val == *".pdf" ]]; then
    nv=$(echo $val | cut -d '.' -f 1)
    parts[$key]=$nv
  fi
done

# check number of values in array, if test number not specified set it to 1
if [[ ${#parts[@]} -eq 2 ]]; then
  parts[2]=1
fi

# write the markdown template
sed "s|<SUBJECTID>|${parts[0]}|g" $mdTemplate > md_template.md
sed -i "s|<EYE>|${parts[1]}|g" md_template.md
sed -i "s|<VISIT>|${parts[2]}|g" md_template.md

# Build the overlay pdf
pandoc md_template.md -o md_template.pdf
pdftk "$1" stamp $VFReportMask output VFReport_masked.pdf

pdftk VFReport_masked.pdf stamp md_template.pdf output VFReport_masked_final.pdf

# move the anon file
cp VFReport_masked_final.pdf "$2/$filename"

# clean up
rm md_template.md
rm md_template.pdf
rm VFReport_masked.pdf
rm VFReport_masked_final.pdf
