#!/bin/bash

mdTemplate='/usr/local/Resources/anonymiseHVF/md_template_anon.md'
VFReportMask='/usr/local/Resources/anonymiseHVF/VFReport_mask.pdf'

filename=$( basename "$1" )

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
sed "s|<SUBJECTID>|${parts[0]}|g" $mdTemplate > /tmp/md_template.md
sed -i "s|<EYE>|${parts[1]}|g" /tmp/md_template.md
sed -i "s|<VISIT>|${parts[2]}|g" /tmp/md_template.md

# Build the overlay pdf
pandoc /tmp/md_template.md -o /tmp/md_template.pdf
pdftk "$1" stamp $VFReportMask output /tmp/VFReport_masked.pdf

pdftk /tmp/VFReport_masked.pdf stamp /tmp/md_template.pdf output /tmp/VFReport_masked_final.pdf

# move the anon file
cp /tmp/VFReport_masked_final.pdf "$2/$filename"

# clean up
rm /tmp/md_template.md
rm /tmp/md_template.pdf
rm /tmp/VFReport_masked.pdf
rm /tmp/VFReport_masked_final.pdf
