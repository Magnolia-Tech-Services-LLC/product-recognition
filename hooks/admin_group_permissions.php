<?php
function HookProduct_recognitionAdmin_group_permissionsAdditionalperms()
{
    global $lang;
    ?>
    <tr class="ListviewTitleStyle">
    <th colspan=3 class="permheader"><?php echo escape($lang["product_recognition"]); ?></th>
    </tr>
    <?php
    # Accepting is privileged: it writes a product tag AND promotes the resource to a reference
    # that steers every later classification. That is more than general edit rights imply.
    # Third arg of DrawOption() is $reverse, NOT a default. Passing true renders the checkbox
    # inverted: ticked means NOT granted, and granting requires unticking. Leave it default.
    DrawOption("product_recognition_accept", $lang["product_recognition_accept_perm"]);
    DrawOption("product_recognition_catalog", $lang["product_recognition_catalog_perm"]);
}
