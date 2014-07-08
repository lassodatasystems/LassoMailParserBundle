<?php
/*
 * Copyright 2013 Lasso Data Systems
 *
 * This file is part of LassoMailParser.
 *
 * LassoMailParser is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * LassoMailParser is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with LassoMailParser. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace Lasso\MailParserBundle;

use Zend\Mail\Storage\Part;

/**
 * Class PartFactory
 *
 * @package Lasso\MailParserBundle
 */
class PartFactory
{
    /**
     * @param string $mailBody
     *
     * @return Part
     */
    public function getPart($mailBody)
    {
        return new Part(['raw' => $mailBody]);
    }
}
